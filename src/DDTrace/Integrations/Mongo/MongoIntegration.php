<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Span;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Integrations\Integration;
use DDTrace\Util\Environment;

final class MongoIntegration
{
    const NAME = 'mongo';

    public static function load()
    {
        if (!extension_loaded('mongo') || Environment::matchesPhpVersion('5.4')) {
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
        $span->setTag(Tag::SPAN_TYPE, Type::MONGO);
        $span->setTag(Tag::SERVICE_NAME, 'mongo');
    }
}
