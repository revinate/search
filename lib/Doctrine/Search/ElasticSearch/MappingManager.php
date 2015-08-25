<?php

namespace Doctrine\Search\ElasticSearch;

use Doctrine\Search\Mapping\ClassMetadata;
use Doctrine\Search\SearchManager;
use Elastica\Index;
use Elastica\Type;

class MappingManager {
    /** @var SearchManager */
    protected $sm;
    /** @var \Doctrine\Search\SearchClientInterface */
    protected $client;
    /** @var string */
    protected $env;

    public function __construct(SearchManager $sm, $env) {
        $this->sm = $sm;
        $this->client = $sm->getClient();
        $this->env = $env;
    }

    /**
     * Refreshes all the templates and mappings
     */
    public function update() {
        $this->updateTemplates();
        $this->updateMappings();
    }

    /**
     * Update existing templates
     */
    public function updateTemplates() {
        $metadatas = $this->sm->getMetadataFactory()->getAllMetadata();
        $indexToMetadatas = array();

        // Refresh the templates used for time series
        /** @var ClassMetadata $metadata */
        foreach ($metadatas as $metadata) {
            if ($metadata->timeSeriesScale) {
                $indexToMetadatas[$metadata->index][] = $metadata;
            }
        }

        if (! empty($indexToMetadatas)) {
            $this->client->createTemplates($indexToMetadatas);
        }
    }

    /**
     * Create new mappings or update existing mappings
     */
    public function updateMappings() {
        /** @var ClassMetadata[] $metadatas */
        $metadatas = $this->sm->getMetadataFactory()->getAllMetadata();

        // Refresh all the mappings
        foreach ($metadatas as $metadata) {
            // if we're in the dev env, set the number of replica to be 0
            if ($this->env == 'dev' || $this->env == 'test_local') {
                $metadata->numberOfReplicas = 0;
            }
            // create the index if it doesn't exist yet
            $indexName = $metadata->timeSeriesScale ? $metadata->getCurrentTimeSeriesIndex() : $metadata->index;
            /** @var Index $index */
            $index = $this->client->getIndex($indexName);
            if (! $index->exists()) {
                $this->client->createIndex($indexName, $metadata->getSettings());
            }
            // create the type if it doesn't exist yet
            $type = new Type($index, $metadata->type);
            if (! $type->exists()) {
                $this->client->createType($metadata);
            }

            // update the mapping
            $this->client->updateMapping($metadata);
        }
    }
}