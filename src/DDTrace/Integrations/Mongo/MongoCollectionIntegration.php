<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Contracts\Span;
use DDTrace\Tag;
use DDTrace\Integrations\Integration;
use DDTrace\Util\Versions;

final class MongoCollectionIntegration extends Integration
{
    const CLASS_NAME = 'MongoCollection';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return MongoIntegration::NAME;
    }

    protected static function loadIntegration()
    {
        if (Versions::phpVersionMatches('5.4')) {
            return;
        }

        $mongoIntegration = MongoIntegration::getInstance();

        // MongoCollection::__construct ( MongoDB $db , string $name )
        self::traceMethod('__construct', function (Span $span, array $args) {
            $span->setTag(Tag::MONGODB_DATABASE, (string) $args[0]);
            $span->setTag(Tag::MONGODB_COLLECTION, $args[1]);
        }, null, $mongoIntegration);
        // int MongoCollection::count ([ array $query = array() [, array $options = array() ]] )
        self::traceMethod('count', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        }, null, $mongoIntegration);
        // array MongoCollection::createDBRef ( mixed $document_or_id )
        self::traceMethod('createDBRef', null, function (Span $span, $ref) {
            if (!is_array($ref)) {
                return;
            }
            if (isset($ref['$id'])) {
                $span->setTag(Tag::MONGODB_BSON_ID, (string) $ref['$id']);
            }
            if (isset($ref['$ref'])) {
                $span->setTag(Tag::MONGODB_COLLECTION, $ref['$ref']);
            }
        }, $mongoIntegration);
        // array MongoCollection::distinct ( string $key [, array $query ] )
        self::traceMethod('distinct', function (Span $span, array $args) {
            if (isset($args[1])) {
                $span->setTraceAnalyticsCandidate();
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[1]));
            }
        }, null, $mongoIntegration);
        // MongoCursor MongoCollection::find ([ array $query = array() [, array $fields = array() ]] )
        self::traceMethod('find', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTraceAnalyticsCandidate();
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        }, null, $mongoIntegration);
        // array MongoCollection::findAndModify ( array $query [, array $update [, array $fields [, array $options ]]] )
        self::traceMethod('findAndModify', function (Span $span, array $args) {
            $span->setTraceAnalyticsCandidate();
            $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
        }, null, $mongoIntegration);
        // array MongoCollection::findOne ([ array $query = array() [, array $fields = array()
        // [, array $options = array() ]]] )
        self::traceMethod('findOne', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTraceAnalyticsCandidate();
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        }, null, $mongoIntegration);
        // array MongoCollection::getDBRef ( array $ref )
        self::traceMethod('getDBRef', function (Span $span, array $args) {
            if (isset($args[0]['$id'])) {
                $span->setTag(Tag::MONGODB_BSON_ID, $args[0]['$id']);
            }
            if (isset($args[0]['$ref'])) {
                $span->setTag(Tag::MONGODB_COLLECTION, $args[0]['$ref']);
            }
        }, null, $mongoIntegration);
        // bool|array MongoCollection::remove ([ array $criteria = array() [, array $options = array() ]] )
        self::traceMethod('remove', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        }, null, $mongoIntegration);
        // bool MongoCollection::setReadPreference ( string $read_preference [, array $tags ] )
        self::traceMethod('setReadPreference', function (Span $span, array $args) {
            $span->setTag(Tag::MONGODB_READ_PREFERENCE, $args[0]);
        }, null, $mongoIntegration);
        // bool|array MongoCollection::update ( array $criteria , array $new_object [, array $options = array() ] )
        self::traceMethod('update', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTraceAnalyticsCandidate();
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        }, null, $mongoIntegration);

        // Methods that don't need extra tags added
        self::traceMethod('aggregate', null, null, $mongoIntegration);
        self::traceMethod('aggregateCursor', null, null, $mongoIntegration);
        self::traceMethod('batchInsert', null, null, $mongoIntegration);
        self::traceMethod('createIndex', null, null, $mongoIntegration);
        self::traceMethod('deleteIndex', null, null, $mongoIntegration);
        self::traceMethod('deleteIndexes', null, null, $mongoIntegration);
        self::traceMethod('drop', null, null, $mongoIntegration);
        self::traceMethod('getIndexInfo', null, null, $mongoIntegration);
        self::traceMethod('getName', null, null, $mongoIntegration);
        self::traceMethod('getReadPreference', null, null, $mongoIntegration);
        self::traceMethod('getWriteConcern', null, null, $mongoIntegration);
        self::traceMethod('group', null, null, $mongoIntegration);
        self::traceMethod('insert', null, null, $mongoIntegration);
        self::traceMethod('parallelCollectionScan', null, null, $mongoIntegration);
        self::traceMethod('save', null, null, $mongoIntegration);
        self::traceMethod('setWriteConcern', null, null, $mongoIntegration);
        self::traceMethod('validate', null, null, $mongoIntegration);
    }

    /**
     * @param Span $span
     * @param string $method
     */
    public static function setDefaultTags(Span $span, $method)
    {
        MongoIntegration::setDefaultTags($span, $method);
    }
}
