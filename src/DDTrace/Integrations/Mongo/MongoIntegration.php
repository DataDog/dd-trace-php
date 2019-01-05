<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tags;
use DDTrace\Types;
use DDTrace\Integrations\Integration;

final class MongoIntegration
{
    const NAME = 'mongo';

    public static function load()
    {
        if (!extension_loaded('mongo')) {
            // Mongodb integration is provided through an extension and not through a class loader.
            return Integration::NOT_AVAILABLE;
        }
        MongoClientIntegration::load();
        MongoDBIntegration::load();
        MongoCollectionIntegration::load();

        return Integration::LOADED;
    }

    /**
     * @param Span $span
     * @param string $method
     */
    public static function setDefaultTags(Span $span, $method)
    {
        Integration::setDefaultTags($span, $method);
        $span->setTag(Tags\SPAN_TYPE, Types\MONGO);
        $span->setTag(Tags\SERVICE_NAME, 'mongo');
    }
}
