<?php

namespace DDTrace\Integrations\Mongo;

class MongoIntegration
{
    public static function load()
    {
        if (!extension_loaded('mongo')) {
            return;
        }
        MongoClientIntegration::load();
    }
}
