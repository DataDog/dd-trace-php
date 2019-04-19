<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Contracts\Span;
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

        $mongoIntegration = MongoIntegration::getInstance();

        // array MongoDB::command ( array $command [, array $options = array() [, string &$hash ]] )
        self::traceMethod('command', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            if (isset($args[0]['query'])) {
                $span->setTag(Tag::MONGODB_QUERY, json_encode($args[0]['query']));
                $span->setTraceAnalyticsCandidate();
            }
            if (isset($args[1]['socketTimeoutMS'])) {
                $span->setTag(Tag::MONGODB_TIMEOUT, $args[1]['socketTimeoutMS']);
            } elseif (isset($args[1]['timeout'])) {
                $span->setTag(Tag::MONGODB_TIMEOUT, $args[1]['timeout']);
            }
        }, null, $mongoIntegration);
        // array MongoDB::createDBRef ( string $collection , mixed $document_or_id )
        self::traceMethod('createDBRef', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_COLLECTION, $args[0]);
        }, function (Span $span, $ref) {
            if (is_array($ref) && isset($ref['$id'])) {
                $span->setTag(Tag::MONGODB_BSON_ID, (string) $ref['$id']);
            }
        }, $mongoIntegration);
        // MongoCollection MongoDB::createCollection ( string $name [, array $options ] )
        self::traceMethod('createCollection', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_COLLECTION, $args[0]);
        }, null, $mongoIntegration);
        // MongoCollection MongoDB::selectCollection ( string $name )
        self::traceMethod('selectCollection', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_COLLECTION, $args[0]);
        }, null, $mongoIntegration);
        // array MongoDB::getDBRef ( array $ref )
        self::traceMethod('getDBRef', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            if (isset($args[0]['$ref'])) {
                $span->setTag(Tag::MONGODB_COLLECTION, $args[0]['$ref']);
            }
        }, null, $mongoIntegration);
        // int MongoDB::setProfilingLevel ( int $level )
        self::traceMethod('setProfilingLevel', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_PROFILING_LEVEL, $args[0]);
        }, null, $mongoIntegration);
        // bool MongoDB::setReadPreference ( string $read_preference [, array $tags ] )
        self::traceMethod('setReadPreference', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_READ_PREFERENCE, $args[0]);
        }, null, $mongoIntegration);

        $setIntegration = function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
        };

        // Methods that don't need extra tags added
        self::traceMethod('drop', null, null, $mongoIntegration);
        self::traceMethod('execute', null, null, $mongoIntegration);
        self::traceMethod('getCollectionInfo', null, null, $mongoIntegration);
        self::traceMethod('getCollectionNames', null, null, $mongoIntegration);
        self::traceMethod('getGridFS', null, null, $mongoIntegration);
        self::traceMethod('getProfilingLevel', null, null, $mongoIntegration);
        self::traceMethod('getReadPreference', null, null, $mongoIntegration);
        self::traceMethod('getWriteConcern', null, null, $mongoIntegration);
        self::traceMethod('lastError', null, null, $mongoIntegration);
        self::traceMethod('listCollections', null, null, $mongoIntegration);
        self::traceMethod('repair', null, null, $mongoIntegration);
        self::traceMethod('setWriteConcern', null, null, $mongoIntegration);
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
