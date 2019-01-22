<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tag;
use DDTrace\Integrations\Integration;
use DDTrace\Util\Environment;

final class MongoCollectionIntegration extends Integration
{
    const CLASS_NAME = 'MongoCollection';

    protected static function loadIntegration()
    {
        if (Environment::matchesPhpVersion('5.4')) {
            return;
        }

        // MongoCollection::__construct ( MongoDB $db , string $name )
        self::traceMethod('__construct', function (Span $span, array $args) {
            $span->setTag(Tag::MONGODB_DATABASE, (string) $args[0]);
            $span->setTag(Tag::MONGODB_COLLECTION, $args[1]);
        });
        // int MongoCollection::count ([ array $query = array() [, array $options = array() ]] )
        self::traceMethod('count', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        });
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
        });
        // array MongoCollection::distinct ( string $key [, array $query ] )
        self::traceMethod('distinct', function (Span $span, array $args) {
            if (isset($args[1])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[1]));
            }
        });
        // MongoCursor MongoCollection::find ([ array $query = array() [, array $fields = array() ]] )
        self::traceMethod('find', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        });
        // array MongoCollection::findAndModify ( array $query [, array $update [, array $fields [, array $options ]]] )
        self::traceMethod('findAndModify', function (Span $span, array $args) {
            $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
        });
        // array MongoCollection::findOne ([ array $query = array() [, array $fields = array()
        // [, array $options = array() ]]] )
        self::traceMethod('findOne', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        });
        // array MongoCollection::getDBRef ( array $ref )
        self::traceMethod('getDBRef', function (Span $span, array $args) {
            if (isset($args[0]['$id'])) {
                $span->setTag(Tag::MONGODB_BSON_ID, $args[0]['$id']);
            }
            if (isset($args[0]['$ref'])) {
                $span->setTag(Tag::MONGODB_COLLECTION, $args[0]['$ref']);
            }
        });
        // bool|array MongoCollection::remove ([ array $criteria = array() [, array $options = array() ]] )
        self::traceMethod('remove', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        });
        // bool MongoCollection::setReadPreference ( string $read_preference [, array $tags ] )
        self::traceMethod('setReadPreference', function (Span $span, array $args) {
            $span->setTag(Tag::MONGODB_READ_PREFERENCE, $args[0]);
        });
        // bool|array MongoCollection::update ( array $criteria , array $new_object [, array $options = array() ] )
        self::traceMethod('update', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]));
            }
        });
        // Methods that don't need extra tags added
        self::traceMethod('aggregate');
        self::traceMethod('aggregateCursor');
        self::traceMethod('batchInsert');
        self::traceMethod('createIndex');
        self::traceMethod('deleteIndex');
        self::traceMethod('deleteIndexes');
        self::traceMethod('drop');
        self::traceMethod('getIndexInfo');
        self::traceMethod('getName');
        self::traceMethod('getReadPreference');
        self::traceMethod('getWriteConcern');
        self::traceMethod('group');
        self::traceMethod('insert');
        self::traceMethod('parallelCollectionScan');
        self::traceMethod('save');
        self::traceMethod('setWriteConcern');
        self::traceMethod('validate');
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
