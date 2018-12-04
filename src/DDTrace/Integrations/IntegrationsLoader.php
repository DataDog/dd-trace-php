<?php

namespace DDTrace\Integrations;

use DDTrace\Configuration;
use DDTrace\Integrations\Curl\CurlIntegration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;
use DDTrace\Integrations\Eloquent\EloquentIntegration;
use DDTrace\Integrations\Guzzle\V5\GuzzleIntegration;
use DDTrace\Integrations\Memcached\MemcachedIntegration;
use DDTrace\Integrations\Mysqli\MysqliIntegration;
use DDTrace\Integrations\PDO\PDOIntegration;
use DDTrace\Integrations\Predis\PredisIntegration;

/**
 * Loader for all integrations currently enabled.
 */
class IntegrationsLoader
{
    const LIBRARIES = [
        CurlIntegration::NAME => '\DDTrace\Integrations\Curl\CurlIntegration',
        ElasticSearchIntegration::NAME => '\DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration',
        EloquentIntegration::NAME => '\DDTrace\Integrations\Eloquent\EloquentIntegration',
        GuzzleIntegration::NAME => '\DDTrace\Integrations\Guzzle\V5\GuzzleIntegration',
        MemcachedIntegration::NAME => '\DDTrace\Integrations\Memcached\MemcachedIntegration',
        MysqliIntegration::NAME => '\DDTrace\Integrations\Mysqli\MysqliIntegration',
        PDOIntegration::NAME => '\DDTrace\Integrations\PDO\PDOIntegration',
        PredisIntegration::NAME => '\DDTrace\Integrations\Predis\PredisIntegration',
    ];

    /**
     * Loads all the enabled library integrations.
     */
    public static function load()
    {
        $globalConfig = Configuration::instance();

        if (!$globalConfig->isEnabled()) {
            return;
        }

        if (!extension_loaded('ddtrace')) {
            error_log('Missing ddtrace extension. To disable tracing set env variable DD_TRACE_ENABLED=false');
            return;
        }

        foreach (self::LIBRARIES as $name => $class) {
            if ($globalConfig->isIntegrationEnabled($name)) {
                call_user_func([$class, 'load']);
            }
        }
    }
}
