<?php

namespace DDTrace\Integrations;

use DDTrace\Configuration;
use DDTrace\Integrations\Curl\CurlIntegration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;
use DDTrace\Integrations\Eloquent\EloquentIntegration;
use DDTrace\Integrations\Guzzle\V5\GuzzleIntegration;
use DDTrace\Integrations\Memcached\MemcachedIntegration;
use DDTrace\Integrations\Mongo\MongoIntegration;
use DDTrace\Integrations\Mysqli\MysqliIntegration;
use DDTrace\Integrations\PDO\PDOIntegration;
use DDTrace\Integrations\Predis\PredisIntegration;

/**
 * Loader for all integrations currently enabled.
 */
class IntegrationsLoader
{
    /**
     * @return array A list of supported library integrations. Web frameworks ARE NOT INCLUDED.
     */
    private static function allLibraries()
    {
        return [
            CurlIntegration::NAME => '\DDTrace\Integrations\Curl\CurlIntegration',
            ElasticSearchIntegration::NAME => '\DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration',
            EloquentIntegration::NAME => '\DDTrace\Integrations\Eloquent\EloquentIntegration',
            GuzzleIntegration::NAME => '\DDTrace\Integrations\Guzzle\V5\GuzzleIntegration',
            MemcachedIntegration::NAME => '\DDTrace\Integrations\Memcached\MemcachedIntegration',
            MongoIntegration::NAME => '\DDTrace\Integrations\Mongo\MongoIntegration',
            MysqliIntegration::NAME => '\DDTrace\Integrations\Mysqli\MysqliIntegration',
            PDOIntegration::NAME => '\DDTrace\Integrations\PDO\PDOIntegration',
            PredisIntegration::NAME => '\DDTrace\Integrations\Predis\PredisIntegration',
        ];
    }

    /**
     * Loads all the enabled library integrations.
     */
    public static function load()
    {
        $globalConfig = Configuration::get();

        if (!$globalConfig->isEnabled()) {
            return;
        }

        if (!extension_loaded('ddtrace')) {
            trigger_error(
                'Missing ddtrace extension. To disable tracing set env variable DD_TRACE_ENABLED=false',
                E_USER_WARNING
            );
            return;
        }

        foreach (self::allLibraries() as $name => $class) {
            if ($globalConfig->isIntegrationEnabled($name)) {
                call_user_func([$class, 'load']);
            }
        }
    }
}
