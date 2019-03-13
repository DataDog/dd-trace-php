<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tag;
use DDTrace\Integrations\Integration;
use DDTrace\Util\Versions;

final class MongoDBIntegration extends Integration
{
    const CLASS_NAME = 'MongoDB';

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

        // array MongoDB::command ( array $command [, array $options = array() [, string &$hash ]] )
        self::traceMethod('command', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
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
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_COLLECTION, $args[0]);
        }, function (Span $span, $ref) {
            if (is_array($ref) && isset($ref['$id'])) {
                $span->setTag(Tag::MONGODB_BSON_ID, (string) $ref['$id']);
            }
        });
        // MongoCollection MongoDB::createCollection ( string $name [, array $options ] )
        self::traceMethod('createCollection', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_COLLECTION, $args[0]);
        });
        // MongoCollection MongoDB::selectCollection ( string $name )
        self::traceMethod('selectCollection', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_COLLECTION, $args[0]);
        });
        // array MongoDB::getDBRef ( array $ref )
        self::traceMethod('getDBRef', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            if (isset($args[0]['$ref'])) {
                $span->setTag(Tag::MONGODB_COLLECTION, $args[0]['$ref']);
            }
        });
        // int MongoDB::setProfilingLevel ( int $level )
        self::traceMethod('setProfilingLevel', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_PROFILING_LEVEL, $args[0]);
        });
        // bool MongoDB::setReadPreference ( string $read_preference [, array $tags ] )
        self::traceMethod('setReadPreference', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_READ_PREFERENCE, $args[0]);
        });

        $setIntegration = function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
        };

        // Methods that don't need extra tags added
        self::traceMethod('drop', $setIntegration);
        self::traceMethod('execute', $setIntegration);
        self::traceMethod('getCollectionInfo', $setIntegration);
        self::traceMethod('getCollectionNames', $setIntegration);
        self::traceMethod('getGridFS', $setIntegration);
        self::traceMethod('getProfilingLevel', $setIntegration);
        self::traceMethod('getReadPreference', $setIntegration);
        self::traceMethod('getWriteConcern', $setIntegration);
        self::traceMethod('lastError', $setIntegration);
        self::traceMethod('listCollections', $setIntegration);
        self::traceMethod('repair', $setIntegration);
        self::traceMethod('setWriteConcern', $setIntegration);
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
