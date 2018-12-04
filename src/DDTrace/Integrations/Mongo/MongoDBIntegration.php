<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tags;
use DDTrace\Integrations\Integration;

final class MongoDBIntegration extends Integration
{
    const CLASS_NAME = 'MongoDB';

    protected static function loadIntegration()
    {
        self::traceMethod('command', function (Span $span, array $args) {
            if (isset($args[0]['query'])) {
                $span->setTag(Tags\MONGODB_QUERY, json_encode($args[0]['query']));
            }
            if (isset($args[1]['socketTimeoutMS'])) {
                $span->setTag(Tags\MONGODB_TIMEOUT, $args[1]['socketTimeoutMS']);
            } elseif (isset($args[1]['timeout'])) {
                $span->setTag(Tags\MONGODB_TIMEOUT, $args[1]['timeout']);
            }
        });
        self::traceMethod('createDBRef', function (Span $span, array $args) {
            $span->setTag(Tags\MONGODB_COLLECTION, $args[0]);
        }, function (Span $span, $ref) {
            if (is_array($ref) && isset($ref['$id'])) {
                $span->setTag(Tags\MONGODB_BSON_ID, (string) $ref['$id']);
            }
        });
        self::traceMethod('createCollection', function (Span $span, array $args) {
            $span->setTag(Tags\MONGODB_COLLECTION, $args[0]);
        });
        self::traceMethod('selectCollection', function (Span $span, array $args) {
            $span->setTag(Tags\MONGODB_COLLECTION, $args[0]);
        });
        self::traceMethod('getDBRef', function (Span $span, array $args) {
            if (isset($args[0]['$ref'])) {
                $span->setTag(Tags\MONGODB_COLLECTION, $args[0]['$ref']);
            }
        });
        self::traceMethod('setProfilingLevel', function (Span $span, array $args) {
            $span->setTag(Tags\MONGODB_PROFILING_LEVEL, $args[0]);
        });
        self::traceMethod('setReadPreference', function (Span $span, array $args) {
            $span->setTag(Tags\MONGODB_READ_PREFERENCE, $args[0]);
        });
        self::traceMethod('drop');
        self::traceMethod('execute');
        self::traceMethod('forceError');
        self::traceMethod('getCollectionInfo');
        self::traceMethod('getCollectionNames');
        self::traceMethod('getGridFS');
        self::traceMethod('getProfilingLevel');
        self::traceMethod('getReadPreference');
        self::traceMethod('getSlaveOkay');
        self::traceMethod('getWriteConcern');
        self::traceMethod('lastError');
        self::traceMethod('listCollections');
        self::traceMethod('prevError');
        self::traceMethod('repair');
        self::traceMethod('resetError');
        self::traceMethod('setSlaveOkay');
        self::traceMethod('setWriteConcern');
    }

    public static function setDefaultTags(Span $span, $method)
    {
        MongoIntegration::setDefaultTags($span, $method);
    }
}
