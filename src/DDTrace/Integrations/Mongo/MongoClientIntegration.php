<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tags;
use DDTrace\Types;
use DDTrace\Integrations\Integration;

class MongoClientIntegration extends Integration
{
    const CLASS_NAME = 'MongoClient';

    protected static function loadIntegration()
    {
        self::traceMethod('__construct', function (Span $span, array $args) {
            if (isset($args[0])) {
                $span->setTag('mongodb.server', $args[0]);
            }
            if (isset($args[1]['db'])) {
                $span->setTag('mongodb.db', $args[1]['db']);
            }
        });
    }

    public static function setDefaultTags(Span $span, $method)
    {
        parent::setDefaultTags($span, $method);
        $span->setTag(Tags\SPAN_TYPE, Types\MONGO);
        $span->setTag(Tags\SERVICE_NAME, 'mongo');
    }
}
