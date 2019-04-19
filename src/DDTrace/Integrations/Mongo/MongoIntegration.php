<?php

namespace DDTrace\Integrations\Mongo;

use DDTrace\Contracts\Span;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Versions;

final class MongoIntegration extends Integration
{
    const NAME = 'mongo';

    /**
     * @var self
     */
    private static $instance;

    /**
     * @return self
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    public static function load()
    {
        if (!extension_loaded('mongo') || Versions::phpVersionMatches('5.4')) {
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
