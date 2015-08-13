<?php

namespace Doctrine\Search\ElasticSearch;

use Doctrine\Search\Mapping\ClassMetadata;
use Doctrine\Search\SearchManager;

class MappingManager {
    /** @var SearchManager */
    protected $sm;
    /** @var \Doctrine\Search\SearchClientInterface */
    protected $client;

    public function __construct(SearchManager $sm) {
        $this->sm = $sm;
        $this->client = $sm->getClient();
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
        $metadatas = $this->sm->getMetadataFactory()->getAllMetadata();

        // Refresh all the mappings
        foreach ($metadatas as $metadata) {
            if (!$this->client->getIndex($metadata->index)->exists()) {
                $this->client->createIndex($metadata->index);
                $this->client->createType($metadata);
            } else {
                $this->client->updateMapping($metadata);
            }
        }
    }
}