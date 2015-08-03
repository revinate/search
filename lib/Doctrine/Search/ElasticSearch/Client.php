<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\Search\ElasticSearch;

use Doctrine\Search\Criteria\Range;
use Doctrine\Search\ElasticSearch\RevinateElastica\Template;
use Doctrine\Search\Exception\InvalidArgumentException;
use Doctrine\Search\SearchClientInterface;
use Doctrine\Search\Mapping\ClassMetadata;
use Doctrine\Search\Exception\NoResultException;
use Elastica\Client as ElasticaClient;
use Elastica\Facet\Filter;
use Elastica\Filter\BoolAnd;
use Elastica\Filter\HasChild;
use Elastica\Filter\HasParent;
use Elastica\Filter\Terms;
use Elastica\Type;
use Elastica\Type\Mapping;
use Elastica\Document;
use Elastica\Index;
use Elastica\Query\MatchAll;
use Elastica\Filter\Term;
use Elastica\Exception\NotFoundException;
use Elastica\Search;
use Doctrine\Common\Collections\ArrayCollection;
use Elastica\Query;
use Elastica\Query\Filtered;

/**
 * SearchManager for ElasticSearch-Backend
 *
 * @author  Mike Lohmann <mike.h.lohmann@googlemail.com>
 * @author  Markus Bachmann <markus.bachmann@bachi.biz>
 */
class Client implements SearchClientInterface
{
    /**
     * @var ElasticaClient
     */
    private $client;

    /**
     * @param ElasticaClient $client
     */
    public function __construct(ElasticaClient $client)
    {
        $this->client = $client;
    }

    /**
     * @return ElasticaClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * {@inheritDoc}
     */
    public function addDocuments(ClassMetadata $class, array $documents)
    {
        $parameters = $this->getParameters($class->parameters);
        $documentsByIndex = array();

        foreach ($documents as $document) {
            $elasticaDoc = new Document(isset($document["id"]) ? $document["id"] : '');
            foreach ($parameters as $name => $value) {
                if (isset($document[$value])) {
                    if (method_exists($elasticaDoc, "set{$name}")) {
                        $elasticaDoc->{"set{$name}"}($document[$value]);
                    } else {
                        $elasticaDoc->setParam($name, $document[$value]);
                    }
                    unset($document[$value]);
                }
            }
            $elasticaDoc->setData($document);
            $documentsByIndex[$class->getIndexForWrite($document)][] = $elasticaDoc;
        }

        foreach ($documentsByIndex as $index => $documents) {
            $type = $this->getIndex($index)->getType($class->type);

            if (count($documents) > 1) {
                $type->addDocuments($documents);
            } else {
                $type->addDocument(reset($documents));
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function removeDocuments(ClassMetadata $class, array $documents)
    {
        $idsByIndex = array();

        foreach ($documents as $document) {
            if (!method_exists($document, "getId") || is_null($document->getId())) {
                throw new \RuntimeException(__METHOD__ . ": Unable to remove document with no id");
            }
            $idsByIndex[$class->getIndexForWrite($document)][] = $document->getId();
        }

        foreach ($idsByIndex as $index => $ids) {
            $type = $this->getIndex($index)->getType($class->type);
            $type->deleteByQuery(new Query\Terms('id', $ids));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function removeAll(ClassMetadata $class, $query = null)
    {
        $type = $this->getIndex($class->getIndexForRead())->getType($class->type);
        $query = $query ?: new MatchAll();
        $type->deleteByQuery($query);
    }

    /**
     * {@inheritDoc}
     */
    public function find(ClassMetadata $class, $id, $options = array())
    {
        return $this->findOneBy($class, $class->getIdentifier(), $id);
    }

    /**
     * {@inheritDoc}
     */
    public function findOneBy(ClassMetadata $class, $field, $value)
    {
        $filter = new Term(array($field => $value));

        $query = new Query(new Filtered(null, $filter));
        $query->setVersion(true);
        $query->setSize(1);

        $results = $this->search($query, array($class));
        if (!$results->count()) {
            throw new NoResultException();
        }

        return $results[0];
    }

    /**
     * {@inheritDoc}
     */
    public function findBy(ClassMetadata $class, array $criteria, array $orderBy = null, $limit = null, $offset = null) {
        $query = new Query();
        if (empty($criteria)) {
            $query->setQuery(new MatchAll());
        } else {
            $boolAndFilter = new BoolAnd();
            foreach ($criteria as $key => $value) {
                if ($this->isHasChild($key) || $this->isHasParent($key)) {
                    $filter = $this->getFilterForHasParentOrHasChild($key, $value);
                } elseif ($value instanceof Range) {
                    $filter = $this->getRangeFilter($key, $value);
                } elseif (is_array($value)) {
                    $filter = new Terms($key, $value);
                } else {
                    $filter = new Term(array($key => $value));
                }
                $boolAndFilter->addFilter($filter);
            }
            $query->setQuery(new Filtered(null, $boolAndFilter));
        }

        if ($orderBy) {
            $query->setSort($orderBy);
        }

        if ($limit) {
            $query->setSize($limit);
        }

        if ($offset) {
            $query->setFrom($offset);
        }

        $results = $this->search($query, array($class));
        if (!$results->count()) {
            throw new NoResultException();
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function findAll(array $classes)
    {
        return $this->buildQuery($classes)->search();
    }

    protected function buildQuery(array $classes)
    {
        $searchQuery = new Search($this->client);
        $searchQuery->setOption(Search::OPTION_VERSION, true);
        /** @var ClassMetadata $class */
        foreach ($classes as $class) {
            if ($class->getIndexForRead()) {
                $indexObject = $this->getIndex($class->getIndexForRead());
                $searchQuery->addIndex($indexObject);
                if ($class->type) {
                    $searchQuery->addType($indexObject->getType($class->type));
                }
            }
        }
        return $searchQuery;
    }

    /**
     * {@inheritDoc}
     */
    public function search($query, array $classes)
    {
        return $this->buildQuery($classes)->search($query);
    }

    /**
     * {@inheritDoc}
     */
    public function createIndex($name, array $config = array())
    {
        $index = $this->getIndex($name);
        $index->create($config, true);
        return $index;
    }

    /**
     * {@inheritDoc}
     */
    public function getIndex($name)
    {
        return $this->client->getIndex($name);
    }

    /**
     * {@inheritDoc}
     */
    public function deleteIndex($index)
    {
        $this->getIndex($index)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function refreshIndex($index)
    {
        $this->getIndex($index)->refresh();
    }

    /**
     * {@inheritDoc}
     */
    public function createType(ClassMetadata $metadata)
    {
        $type = $this->getIndex($metadata->getCurrentTimeSeriesIndex())->getType($metadata->type);
        $properties = $this->getMapping($metadata->fieldMappings);
        $rootProperties = $this->getRootMapping($metadata->rootMappings);

        $mapping = new Mapping($type, $properties);
        $mapping->disableSource($metadata->source);
        if (isset($metadata->boost)) {
            $mapping->setParam('_boost', array('name' => '_boost', 'null_value' => $metadata->boost));
        }
        if (isset($metadata->parent)) {
            $mapping->setParent($metadata->parent);
        }
        foreach ($rootProperties as $key => $value) {
            $mapping->setParam($key, $value);
        }

        $mapping->send();

        return $type;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteType(ClassMetadata $metadata)
    {
        $type = $this->getIndex($metadata->getIndexForRead())->getType($metadata->type);
        return $type->delete();
    }

    /**
     * Generates property mapping from entity annotations
     *
     * @param array $mappings
     */
    protected function getMapping($mappings)
    {
        $properties = array();

        foreach ($mappings as $propertyName => $fieldMapping) {
            if (isset($fieldMapping->name)) {
                $propertyName = $fieldMapping->name;
            }

            $properties[$propertyName]['type'] = $fieldMapping->type;

            if (isset($fieldMapping->path)) {
                $properties[$propertyName]['path'] = $fieldMapping->path;
            }

            if (isset($fieldMapping->includeInAll)) {
                $properties[$propertyName]['include_in_all'] = $fieldMapping->includeInAll;
            }

            if (isset($fieldMapping->nullValue)) {
                $properties[$propertyName]['null_value'] = $fieldMapping->nullValue;
            }

            if (isset($fieldMapping->store)) {
                $properties[$propertyName]['store'] = $fieldMapping->store;
            }

            if (isset($fieldMapping->index)) {
                $properties[$propertyName]['index'] = $fieldMapping->index;
            }

            if (isset($fieldMapping->boost)) {
                $properties[$propertyName]['boost'] = $fieldMapping->boost;
            }

            if (isset($fieldMapping->analyzer)) {
                $properties[$propertyName]['analyzer'] = $fieldMapping->analyzer;
            }

            if (isset($fieldMapping->indexName)) {
                $properties[$propertyName]['index_name'] = $fieldMapping->indexName;
            }

            if (isset($fieldMapping->dynamic)) {
                $properties[$propertyName]['dynamic'] = $fieldMapping->dynamic;
            }

            if ($fieldMapping->type == 'attachment' && isset($fieldMapping->fields)) {
                $callback = function ($field) {
                    unset($field['type']);
                    return $field;
                };
                $properties[$propertyName]['fields'] = array_map($callback, $this->getMapping($fieldMapping->fields));
            }

            if ($fieldMapping->type == 'multi_field' && isset($fieldMapping->fields)) {
                $properties[$propertyName]['fields'] = $this->getMapping($fieldMapping->fields);
            }

            if (in_array($fieldMapping->type, array('nested', 'object')) && isset($fieldMapping->properties)) {
                $properties[$propertyName]['properties'] = $this->getMapping($fieldMapping->properties);
            }
        }

        return $properties;
    }

    /**
     * {@inheritdoc}
     */
    public function updateMapping(ClassMetadata $classMetadata) {
        try {
            $elasticaIndex = new Index($this->client, $classMetadata->getIndexForRead());
            $elasticaType = new Type($elasticaIndex, $classMetadata->type);
            $elasticaTypeMapping = new Mapping($elasticaType, $this->getMapping($classMetadata->fieldMappings));
            $elasticaTypeMapping->setParam('_id', array('path' => $classMetadata->getIdentifier()));
            if ($classMetadata->dynamic) {
                $elasticaTypeMapping->setParam('dynamic', $classMetadata->dynamic);
            }
            $response = $elasticaType->setMapping($elasticaTypeMapping);
        } catch (\Exception $e) {
            return false;
        }

        return 200 == $response->getStatus();
    }

    /**
     * Generates parameter mapping from entity annotations
     *
     * @param array $paramMapping
     */
    protected function getParameters($paramMapping)
    {
        $parameters = array();
        foreach ($paramMapping as $propertyName => $mapping) {
            $paramName = isset($mapping->name) ? $mapping->name : $propertyName;
            $parameters[$paramName] = $propertyName;
        }
        return $parameters;
    }

    /**
     * Generates root mapping from entity annotations
     *
     * @param array $mappings
     */
    protected function getRootMapping($mappings)
    {
        $properties = array();

        foreach ($mappings as $rootMapping) {
            $propertyName = $rootMapping->name;
            $mapping = array();

            if (isset($rootMapping->value)) {
                $mapping = $rootMapping->value;
            }

            if (isset($rootMapping->match)) {
                $mapping['match'] = $rootMapping->match;
            }

            if (isset($rootMapping->pathMatch)) {
                $mapping['path_match'] = $rootMapping->pathMatch;
            }

            if (isset($rootMapping->unmatch)) {
                $mapping['unmatch'] = $rootMapping->unmatch;
            }

            if (isset($rootMapping->pathUnmatch)) {
                $mapping['path_unmatch'] = $rootMapping->pathUnmatch;
            }

            if (isset($rootMapping->matchPattern)) {
                $mapping['match_pattern'] = $rootMapping->matchPattern;
            }

            if (isset($rootMapping->matchMappingType)) {
                $mapping['match_mapping_type'] = $rootMapping->matchMappingType;
            }

            if (isset($rootMapping->mapping)) {
                $mapping['mapping'] = current($this->getMapping($rootMapping->mapping));
            }

            if (isset($rootMapping->id)) {
                $properties[$propertyName][][$rootMapping->id] = $mapping;
            } else {
                $properties[$propertyName] = $mapping;
            }
        }

        return $properties;
    }

    /**
     * @param string $field
     * @param Range  $range
     *
     * @return \Elastica\Filter\Range
     */
    protected function getRangeFilter($field, Range $range) {
        $rangeArray = array();
        $rangeArray[$range->getComparator1()] = $range->getValue1();

        if ($range->getComparator2()) {
            $rangeArray[$range->getComparator2()] = $range->getValue2();
        }
        return new \Elastica\Filter\Range($field, $rangeArray);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    protected function isHasParent($key) {
        return $this->startsWith($key, '_parent.');
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    protected function isHasChild($key) {
        return $this->startsWith($key, '_child.');
    }

    /**
     * @param string $haystack
     * @param string $needle
     *
     * @return bool
     */
    protected function startsWith($haystack, $needle){
        return strpos($haystack, $needle) === 0;
    }

    /**
     * @param string $key
     * @param string $value
     *
     * @return HasChild|HasParent
     * @throws \Exception
     */
    protected function getFilterForHasParentOrHasChild($key, $value) {
        $parts = explode('.', $key);
        if (3 != count($parts)) {
            throw new InvalidArgumentException(__METHOD__ . ': Find by child or parent must be of the form "_child.type.field" or "_parent.type.field"');
        }

        $type = $parts[1];
        $field = $parts[2];

        if (is_array($value)) {
            $filter = new Terms($field, $value);
        } else {
            $filter = new Term(array($field => $value));
        }

        if ($this->isHasChild($key)) {
            return new HasChild($filter, $type);
        } elseif ($this->isHasParent($key)) {
            return new HasParent($filter, $type);
        } else {
            throw new \Exception(__METHOD__ . ': Programming error');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createTemplates($indexToMetadatas) {
        $elasticaTemplate = new Template($this->client);

        try {
            foreach ($indexToMetadatas as $index => $metadatas) {
                $mappings = array();
                /** @var ClassMetadata $metadata */
                foreach ($metadatas as $metadata) {
                    $mappings[$metadata->type]['_id']['path'] = $metadata->getIdentifier();
                    if ($metadata->dynamic) {
                        $mappings[$metadata->type]['dynamic'] = $metadata->dynamic;
                    }
                    $mappings[$metadata->type]['properties'] = $this->getMapping($metadata->fieldMappings);
                }
                $response = $elasticaTemplate->createTemplate($metadata->index, $metadata->getIndexForRead(), $metadata->getSettings(), $mappings);
            }
        } catch (\Exception $e) {
            return false;
        }

        return 200 == $response->getStatus();
    }

    /**
     * {@inheritdoc}
     */
    public function getTemplate(ClassMetadata $classMetadata) {
        $elasticaTemplate = new Template($this->client);

        try {
            $template = $elasticaTemplate->getTemplate($classMetadata->index);
        } catch (\Exception $e) {
            throw new NoResultException();
        }
        return $template;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTemplate(ClassMetadata $classMetadata) {
        $elasticaTemplate = new Template($this->client);

        try {
            $response = $elasticaTemplate->deleteTemplate($classMetadata->index);
        } catch (\Exception $e) {
            return false;
        }
        return 200 == $response->getStatus();
    }
}
