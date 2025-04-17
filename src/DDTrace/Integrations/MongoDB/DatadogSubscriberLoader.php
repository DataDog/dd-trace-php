<?php

namespace DDTrace\Integrations\MongoDB;

class DatadogSubscriberLoader
{
    public static function load()
    {
        if (version_compare(PHP_VERSION, '7.2.0', '>=')) {
            return new DatadogSubscriberWithReturnTypes();
        }
        return new DatadogSubscriberWithoutReturnTypes();
    }
}