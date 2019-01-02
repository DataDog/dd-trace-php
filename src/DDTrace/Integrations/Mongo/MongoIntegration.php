<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Integrations\Integration;

final class MongoIntegration
{
    const NAME = 'mongo';

    public static function load()
    {
        if (!extension_loaded('mongo')) {
            return;
        }
        MongoClientIntegration::load();
        MongoDBIntegration::load();
        MongoCollectionIntegration::load();
    }

    /**
     * @param Span $span
     * @param string $method
     */
    public static function setDefaultTags(Span $span, $method)
    {
        Integration::setDefaultTags($span, $method);
        $span->setTag(Tag::SPAN_TYPE, Type::MONGO);
        $span->setTag(Tag::SERVICE_NAME, 'mongo');
    }
}
