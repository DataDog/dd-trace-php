<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tags;
use DDTrace\Integrations\Integration;

final class MongoCollectionIntegration extends Integration
{
    const CLASS_NAME = 'MongoCollection';

    protected static function loadIntegration()
    {
        self::traceMethod('__construct', function (Span $span, array $args) {
            $span->setTag(Tags\MONGODB_DATABASE, (string) $args[0]);
            $span->setTag(Tags\MONGODB_COLLECTION, $args[1]);
        });
        self::traceMethod('count', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tags\MONGODB_QUERY, json_encode($args[0]));
            }
        });
        self::traceMethod('createDBRef', null, function (Span $span, $ref) {
            if (!is_array($ref)) {
                return;
            }
            if (isset($ref['$id'])) {
                $span->setTag(Tags\MONGODB_BSON_ID, (string) $ref['$id']);
            }
            if (isset($ref['$ref'])) {
                $span->setTag(Tags\MONGODB_COLLECTION, $ref['$ref']);
            }
        });
        self::traceMethod('distinct', function (Span $span, array $args) {
            if (isset($args[1])) {
                $span->setTag(Tags\MONGODB_QUERY, json_encode($args[1]));
            }
        });
        self::traceMethod('find', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tags\MONGODB_QUERY, json_encode($args[0]));
            }
        });
        self::traceMethod('findAndModify', function (Span $span, array $args) {
            $span->setTag(Tags\MONGODB_QUERY, json_encode($args[0]));
        });
        self::traceMethod('findOne', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tags\MONGODB_QUERY, json_encode($args[0]));
            }
        });
        self::traceMethod('getDBRef', function (Span $span, array $args) {
            if (isset($args[0]['$id'])) {
                $span->setTag(Tags\MONGODB_BSON_ID, $args[0]['$id']);
            }
            if (isset($args[0]['$ref'])) {
                $span->setTag(Tags\MONGODB_COLLECTION, $args[0]['$ref']);
            }
        });
        self::traceMethod('remove', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tags\MONGODB_QUERY, json_encode($args[0]));
            }
        });
        self::traceMethod('setReadPreference', function (Span $span, array $args) {
            $span->setTag(Tags\MONGODB_READ_PREFERENCE, $args[0]);
        });
        self::traceMethod('update', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tags\MONGODB_QUERY, json_encode($args[0]));
            }
        });
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
        self::traceMethod('getSlaveOkay');
        self::traceMethod('getWriteConcern');
        self::traceMethod('group');
        self::traceMethod('insert');
        self::traceMethod('parallelCollectionScan');
        self::traceMethod('save');
        self::traceMethod('setSlaveOkay');
        self::traceMethod('setWriteConcern');
        self::traceMethod('validate');
    }

    public static function setDefaultTags(Span $span, $method)
    {
        MongoIntegration::setDefaultTags($span, $method);
    }
}
