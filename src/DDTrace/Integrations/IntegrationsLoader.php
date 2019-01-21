<?php

namespace DDTrace\Integrations;

use DDTrace\Configuration;
use DDTrace\Integrations\Curl\CurlIntegration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;
use DDTrace\Integrations\Eloquent\EloquentIntegration;
use DDTrace\Integrations\Guzzle\V5\GuzzleIntegration;
use DDTrace\Integrations\Laravel\LaravelIntegration;
use DDTrace\Integrations\Memcached\MemcachedIntegration;
use DDTrace\Integrations\Mongo\MongoIntegration;
use DDTrace\Integrations\Mysqli\MysqliIntegration;
use DDTrace\Integrations\PDO\PDOIntegration;
use DDTrace\Integrations\Predis\PredisIntegration;
use DDTrace\Integrations\Symfony\SymfonyIntegration;
use DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration;

/**
 * Loader for all integrations currently enabled.
 */
class IntegrationsLoader
{
    /**
     * @var IntegrationsLoader
     */
    private static $instance;

    /**
     * @var array
     */
    private $integrations = [];

    /**
     * @var array
     */
    public static $officiallySupportedIntegrations = [
        CurlIntegration::NAME => '\DDTrace\Integrations\Curl\CurlIntegration',
        ElasticSearchIntegration::NAME => '\DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration',
        EloquentIntegration::NAME => '\DDTrace\Integrations\Eloquent\EloquentIntegration',
        GuzzleIntegration::NAME => '\DDTrace\Integrations\Guzzle\V5\GuzzleIntegration',
        LaravelIntegration::NAME => '\DDTrace\Integrations\Laravel\LaravelIntegration',
        MemcachedIntegration::NAME => '\DDTrace\Integrations\Memcached\MemcachedIntegration',
        MongoIntegration::NAME => '\DDTrace\Integrations\Mongo\MongoIntegration',
        MysqliIntegration::NAME => '\DDTrace\Integrations\Mysqli\MysqliIntegration',
        PDOIntegration::NAME => '\DDTrace\Integrations\PDO\PDOIntegration',
        PredisIntegration::NAME => '\DDTrace\Integrations\Predis\PredisIntegration',
        SymfonyIntegration::NAME => '\DDTrace\Integrations\Symfony\SymfonyIntegration',
        ZendFrameworkIntegration::NAME => '\DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration',
    ];

    /**
     * @var array Registry to keep track of integrations loading status.
     */
    private $loadings = [];

    /**
     * @param array|null $integrations
     */
    public function __construct(array $integrations)
    {
        $this->integrations = $integrations;
    }

    /**
     * Returns the singleton integration loader.
     *
     * @return IntegrationsLoader
     */
    public static function get()
    {
        if (null === self::$instance) {
            self::$instance = new IntegrationsLoader(self::$officiallySupportedIntegrations);
        }

        return self::$instance;
    }

    /**
     * Loads all the integrations registered with this loader instance.
     */
    public function loadAll()
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

        foreach ($this->integrations as $name => $class) {
            if (!$globalConfig->isIntegrationEnabled($name)) {
                continue;
            }

            // If the integration has already been loaded, we don't need to reload it. On the other hand, with
            // auto-instrumentation this method may be called many times as the hook is the autoloader callback.
            // So we want to make sure that we do not load the same integration twice if not required.
            $integrationLoadingStatus = $this->getLoadingStatus($name);
            if (in_array($integrationLoadingStatus, [Integration::LOADED, Integration::NOT_AVAILABLE])) {
                continue;
            }

            $this->loadings[$name] = call_user_func([$class, 'load']);
        }
    }

    /**
     * Returns the registered integrations.
     *
     * @return array
     */
    public function getIntegrations()
    {
        return $this->integrations;
    }

    /**
     * Returns the provide integration current loading status.
     *
     * @param string $integrationName
     * @return int
     */
    public function getLoadingStatus($integrationName)
    {
        return isset($this->loadings[$integrationName]) ? $this->loadings[$integrationName] : Integration::NOT_LOADED;
    }

    /**
     * Loads all the enabled library integrations using the global singleton integrations loader which in charge
     * only of the officially supported integrations.
     */
    public static function load()
    {
        self::get()->loadAll();
    }

    public function reset()
    {
        $this->integrations = [];
    }
}
