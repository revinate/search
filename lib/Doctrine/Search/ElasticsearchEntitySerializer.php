<?php
namespace Doctrine\Search;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Search\Exception\InvalidArgumentException;
use Doctrine\Search\Mapping\Annotations\ElasticField;
use Elastica\Result;


class ElasticsearchEntitySerializer {

    /** @var AnnotationReader */
    protected $reader;

    /** @var string */
    protected $annotationClass = 'Doctrine\Search\Mapping\Annotations\ElasticField';

    /** @var ElasticsearchEntitySerializer */
    protected static $instance;

    /**
     * Constructor
     */
    private function __construct() {
        $this->reader = new AnnotationReader();
    }

    /**
     * @return ElasticsearchEntitySerializer
     */
    public static function getInstance() {
        if (! self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Build the ES document
     *
     * @param mixed $entity
     *
     * @return array
     * @throws \Exception
     */
    public function serialize(BaseElasticsearchEntity $entity) {
        $esDocument = array();

        $properties = $this->getAllClassProperties($entity);
        foreach ($properties as $property) {
            $annotation = $this->reader->getPropertyAnnotation($property, $this->annotationClass);

            if ($annotation) {
                /** @var ElasticField $annotation */
                $getter = 'get' . ucfirst($property->name);
                if (! is_callable(array($entity, $getter))) {
                    throw new \Exception('Getter function is not callable: ' . $getter . ' for entity ' . get_class($entity));
                }

                $propertyValue = $entity->$getter();
                switch ($annotation->type) {
                    // @todo[daiyi]: add more handler if necessary
                    case 'date':
                        if ($propertyValue instanceof \DateTime) {
                            switch ($annotation->format) {
                                case 'date':
                                    $propertyValue = $propertyValue->format('Y-m-d');
                                    break;
                                default:
                                    $propertyValue = $propertyValue->format('c');
                                    break;
                            }
                        }
                        break;
                    default:
                        break;
                }

                $esDocument[$property->name] = $propertyValue;

            }
        }
        return $esDocument;
    }

    /**
     * @param string|Result|array     $esDocument
     * @param BaseElasticsearchEntity $deserializingToEntity
     *
     * @return BaseElasticsearchEntity
     * @throws InvalidArgumentException
     * @throws \Exception
     */
    public function deserialize($esDocument, BaseElasticsearchEntity $deserializingToEntity) {
        // some pre process to convert the data into array for the ease of process
        if (is_string($esDocument)) {
            // if it's a string, assume it's a json sting
            $esDocument = json_decode($esDocument, true);
            if (!is_array($esDocument)) {
                throw new InvalidArgumentException(__METHOD__ . ' accepted a string and was not able to json decode the string into a valid array');
            }
        } elseif ($esDocument instanceof Result) {
            $esDocument = $esDocument->getData();
        }

        $properties = $this->getAllClassProperties($deserializingToEntity);
        foreach ($properties as $property) {
            /** @var ElasticField $annotation */
            $annotation = $this->reader->getPropertyAnnotation($property, $this->annotationClass);
            if ($annotation) {
                $setter = 'set' . ucfirst($property->name);
                if (! is_callable(array($deserializingToEntity, $setter))) {
                    throw new \Exception('Setter function is not callable: ' . $setter . ' for entity ' . get_class($deserializingToEntity));
                }

                $propertyValue = isset($esDocument[$property->name]) ? $esDocument[$property->name] : null;
                switch ($annotation->type) {
                    // @todo[daiyi]: add more handler if necessary
                    case 'date':
                        if ($propertyValue) {
                            $propertyValue = new \DateTime($propertyValue);
                        }
                        break;
                    default:
                        break;
                }

                $deserializingToEntity->$setter($propertyValue);
            }
        }

        return $deserializingToEntity;
    }

    /**
     * Helper method that gets all the properties from a reflection obj
     *
     * @param $object
     *
     * @return \ReflectionProperty[]
     */
    protected function getAllClassProperties($object) {
        $reflectionObj = new \ReflectionObject($object);
        $properties = $reflectionObj->getProperties();
        while ($parent = $reflectionObj->getParentClass()) {
            $properties = array_merge($parent->getProperties(), $properties);
            $reflectionObj = $parent;
        }
        return $properties;
    }

}