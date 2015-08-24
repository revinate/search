<?php

namespace Doctrine\Search;

use Elastica\Result;

abstract class BaseElasticsearchEntity {
    /** @var string Index type (override in entity implementation) */
    protected static $indexType = '';

    /**
     * Constructor
     *
     * @param array|Result $esDocument If null, creates a new object else populates given data into the object
     */
    public function __construct($esDocument = null) {
        if ($esDocument) {
            $this->fromESDocument($esDocument);
        }
    }

    /**
     * Get the index type of this entity
     *
     * @return string
     */
    public static function getIndexType() {
        return static::$indexType;
    }


    /**
     * Create object from es document
     *
     * @param array $doc
     *
     * @return static
     */
    public static function createFromESDocument($doc) {
        return new static($doc);
    }

    /**
     * Create ES document for this entity
     *
     * @return array
     */
    public function toESDocument() {
        $serializer = static::getSerializer();
        return $serializer->serialize($this);
    }

    /**
     * Convert to ES entity es docs
     *
     * @param $doc
     * @return BaseElasticsearchEntity
     */
    public function fromESDocument($doc) {
        $serializer = static::getSerializer();
        return $serializer->deserialize($doc, $this);
    }

    /**
     * Get serializer
     *
     * @return ElasticsearchEntitySerializer
     */
    protected static function getSerializer() {
        return ElasticsearchEntitySerializer::getInstance();
    }
}
