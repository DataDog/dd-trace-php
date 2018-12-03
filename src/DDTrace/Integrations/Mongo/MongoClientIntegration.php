<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tags;
use DDTrace\Types;
use DDTrace\Obfuscation;
use DDTrace\Integrations\Integration;

class MongoClientIntegration extends Integration
{
    const CLASS_NAME = 'MongoClient';

    protected static function loadIntegration()
    {
        self::traceMethod('__construct', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag('mongodb.server', Obfuscation::dsn($args[0]));
                $dbName = self::extractDatabaseNameFromDsn($args[0]);
                if (null !== $dbName) {
                    $span->setTag('mongodb.db', $dbName);
                }
            }
            if (isset($args[1]['db'])) {
                $span->setTag('mongodb.db', $args[1]['db']);
            }
        });
    }

    private static function extractDatabaseNameFromDsn($dsn)
    {
        $matches = [];
        if (false === preg_match('/^.+\/\/.+\/(.+)$/', $dsn, $matches)) {
            return $dsn;
        }
        return $matches[1];
    }

    public static function setDefaultTags(Span $span, $method)
    {
        parent::setDefaultTags($span, $method);
        $span->setTag(Tags\SPAN_TYPE, Types\MONGO);
        $span->setTag(Tags\SERVICE_NAME, 'mongo');
    }
}
