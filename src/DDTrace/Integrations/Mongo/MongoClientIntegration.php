<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tags;
use DDTrace\Obfuscation;
use DDTrace\Integrations\Integration;

final class MongoClientIntegration extends Integration
{
    const CLASS_NAME = 'MongoClient';

    protected static function loadIntegration()
    {
        // MongoClient::__construct ([ string $server = "mongodb://localhost:27017"
        // [, array $options = array("connect" => TRUE) [, array $driver_options ]]] )
        self::traceMethod('__construct', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag(Tags\MONGODB_SERVER, Obfuscation::dsn($args[0]));
                $dbName = self::extractDatabaseNameFromDsn($args[0]);
                if (null !== $dbName) {
                    $span->setTag(Tags\MONGODB_DATABASE, $dbName);
                }
            }
            if (isset($args[1]['db'])) {
                $span->setTag(Tags\MONGODB_DATABASE, $args[1]['db']);
            }
        });
        // MongoCollection MongoClient::selectCollection ( string $db , string $collection )
        self::traceMethod('selectCollection', function (Span $span, array $args) {
            $span->setTag(Tags\MONGODB_DATABASE, $args[0]);
            $span->setTag(Tags\MONGODB_COLLECTION, $args[1]);
        });
        // MongoDB MongoClient::selectDB ( string $name )
        self::traceMethod('selectDB', function (Span $span, array $args) {
            $span->setTag(Tags\MONGODB_DATABASE, $args[0]);
        });
        // bool MongoClient::setReadPreference ( string $read_preference [, array $tags ] )
        self::traceMethod('setReadPreference', function (Span $span, array $args) {
            $span->setTag(Tags\MONGODB_READ_PREFERENCE, $args[0]);
        });
        // Methods that don't need extra tags added
        self::traceMethod('getHosts');
        self::traceMethod('getReadPreference');
        self::traceMethod('getWriteConcern');
        self::traceMethod('listDBs');
        self::traceMethod('setWriteConcern');
    }

    // If the `db` option isn't provided via the constructor, we extract
    // the database name from the DSN string if it exists.
    private static function extractDatabaseNameFromDsn($dsn)
    {
        $matches = [];
        if (false === preg_match('/^.+\/\/.+\/(.+)$/', $dsn, $matches)) {
            return null;
        }
        return isset($matches[1]) ? $matches[1] : null;
    }

    public static function setDefaultTags(Span $span, $method)
    {
        MongoIntegration::setDefaultTags($span, $method);
    }
}
