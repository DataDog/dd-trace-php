<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tag;
use DDTrace\Integrations\Integration;
use DDTrace\Util\Environment;

final class MongoDBIntegration extends Integration
{
    const CLASS_NAME = 'MongoDB';

    protected static function loadIntegration()
    {
        if (Environment::matchesPhpVersion('5.4')) {
            return;
        }

        // array MongoDB::command ( array $command [, array $options = array() [, string &$hash ]] )
        self::traceMethod('command', function (Span $span, array $args) {
            if (isset($args[0]['query'])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]['query']));
            }
            if (isset($args[1]['socketTimeoutMS'])) {
                $span->setTag(Tag::MONGODB_TIMEOUT, $args[1]['socketTimeoutMS']);
            } elseif (isset($args[1]['timeout'])) {
                $span->setTag(Tag::MONGODB_TIMEOUT, $args[1]['timeout']);
            }
        });
        // array MongoDB::createDBRef ( string $collection , mixed $document_or_id )
        self::traceMethod('createDBRef', function (Span $span, array $args) {
            $span->setTag(Tag::MONGODB_COLLECTION, $args[0]);
        }, function (Span $span, $ref) {
            if (is_array($ref) && isset($ref['$id'])) {
                $span->setTag(Tag::MONGODB_BSON_ID, (string) $ref['$id']);
            }
        });
        // MongoCollection MongoDB::createCollection ( string $name [, array $options ] )
        self::traceMethod('createCollection', function (Span $span, array $args) {
            $span->setTag(Tag::MONGODB_COLLECTION, $args[0]);
        });
        // MongoCollection MongoDB::selectCollection ( string $name )
        self::traceMethod('selectCollection', function (Span $span, array $args) {
            $span->setTag(Tag::MONGODB_COLLECTION, $args[0]);
        });
        // array MongoDB::getDBRef ( array $ref )
        self::traceMethod('getDBRef', function (Span $span, array $args) {
            if (isset($args[0]['$ref'])) {
                $span->setTag(Tag::MONGODB_COLLECTION, $args[0]['$ref']);
            }
        });
        // int MongoDB::setProfilingLevel ( int $level )
        self::traceMethod('setProfilingLevel', function (Span $span, array $args) {
            $span->setTag(Tag::MONGODB_PROFILING_LEVEL, $args[0]);
        });
        // bool MongoDB::setReadPreference ( string $read_preference [, array $tags ] )
        self::traceMethod('setReadPreference', function (Span $span, array $args) {
            $span->setTag(Tag::MONGODB_READ_PREFERENCE, $args[0]);
        });
        // Methods that don't need extra tags added
        self::traceMethod('drop');
        self::traceMethod('execute');
        self::traceMethod('getCollectionInfo');
        self::traceMethod('getCollectionNames');
        self::traceMethod('getGridFS');
        self::traceMethod('getProfilingLevel');
        self::traceMethod('getReadPreference');
        self::traceMethod('getWriteConcern');
        self::traceMethod('lastError');
        self::traceMethod('listCollections');
        self::traceMethod('repair');
        self::traceMethod('setWriteConcern');
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
