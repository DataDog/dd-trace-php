<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Contracts\Span;
use DDTrace\Tag;
use DDTrace\Obfuscation;
use DDTrace\Integrations\Integration;
use DDTrace\Util\Versions;

final class MongoClientIntegration extends Integration
{
    const CLASS_NAME = 'MongoClient';

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

        // MongoClient::__construct ([ string $server = "mongodb://localhost:27017"
        // [, array $options = array("connect" => TRUE) [, array $driver_options ]]] )
        self::traceMethod('__construct', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            if (isset($args[0])) {
                $span->setTag(Tag::MONGODB_SERVER, Obfuscation::dsn($args[0]));
                $dbName = self::extractDatabaseNameFromDsn($args[0]);
                if (null !== $dbName) {
                    $span->setTag(Tag::MONGODB_DATABASE, $dbName);
                }
            }
            if (isset($args[1]['db'])) {
                $span->setTag(Tag::MONGODB_DATABASE, $args[1]['db']);
            }
        }, null, $mongoIntegration);
        // MongoCollection MongoClient::selectCollection ( string $db , string $collection )
        self::traceMethod('selectCollection', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_DATABASE, $args[0]);
            $span->setTag(Tag::MONGODB_COLLECTION, $args[1]);
        }, null, $mongoIntegration);
        // MongoDB MongoClient::selectDB ( string $name )
        self::traceMethod('selectDB', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_DATABASE, $args[0]);
        }, null, $mongoIntegration);
        // bool MongoClient::setReadPreference ( string $read_preference [, array $tags ] )
        self::traceMethod('setReadPreference', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_READ_PREFERENCE, $args[0]);
        }, null, $mongoIntegration);

        // Methods that don't need extra tags added
        self::traceMethod('getHosts', null, null, $mongoIntegration);
        self::traceMethod('getReadPreference', null, null, $mongoIntegration);
        self::traceMethod('getWriteConcern', null, null, $mongoIntegration);
        self::traceMethod('listDBs', null, null, $mongoIntegration);
        self::traceMethod('setWriteConcern', null, null, $mongoIntegration);
    }

    /**
     * If the `db` option isn't provided via the constructor, we extract
     * the database name from the DSN string if it exists.
     *
     * @param string $dsn
     * @return string|null
     */
    private static function extractDatabaseNameFromDsn($dsn)
    {
        $matches = [];
        if (false === preg_match('/^.+\/\/.+\/(.+)$/', $dsn, $matches)) {
            return null;
        }
        return isset($matches[1]) ? $matches[1] : null;
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
