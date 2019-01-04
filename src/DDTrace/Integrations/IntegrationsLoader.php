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
     * @var array Registry to keep track of integrations loading status.
     */
    private static $loadings = [];

    /**
     * @return array A list of supported library integrations. Web frameworks ARE NOT INCLUDED.
     */
    public static function allLibraries()
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
            if (!$globalConfig->isIntegrationEnabled($name)) {
                continue;
            }

            // If the integration has already been loaded, we don't need to reload it. On the other hand, with
            // auto-instrumentation this method may be called many times as the hook is the autoloader callback.
            // So we want to make sure that we do not load the same integration twice if not required.
            if (isset(self::$loadings[$name])
                    && in_array(self::$loadings[$name], [Integration::LOADED, Integration::NOT_AVAILABLE])
            ) {
                continue;
            }

            self::$loadings[$name] = call_user_func([$class, 'load']);
        }
    }
}
