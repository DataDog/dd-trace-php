<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
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
        });
        // MongoCollection MongoClient::selectCollection ( string $db , string $collection )
        self::traceMethod('selectCollection', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_DATABASE, $args[0]);
            $span->setTag(Tag::MONGODB_COLLECTION, $args[1]);
        });
        // MongoDB MongoClient::selectDB ( string $name )
        self::traceMethod('selectDB', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_DATABASE, $args[0]);
        });
        // bool MongoClient::setReadPreference ( string $read_preference [, array $tags ] )
        self::traceMethod('setReadPreference', function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
            $span->setTag(Tag::MONGODB_READ_PREFERENCE, $args[0]);
        });

        $setIntegration = function (Span $span, array $args) {
            $span->setIntegration(MongoIntegration::getInstance());
        };

        // Methods that don't need extra tags added
        self::traceMethod('getHosts', $setIntegration);
        self::traceMethod('getReadPreference', $setIntegration);
        self::traceMethod('getWriteConcern', $setIntegration);
        self::traceMethod('listDBs', $setIntegration);
        self::traceMethod('setWriteConcern', $setIntegration);
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
