<?php

namespace DDTrace;

use DDTrace\Configuration\AbstractConfiguration;
use DDTrace\Integrations\Curl\CurlConfiguration;
use DDTrace\Integrations\Curl\CurlIntegration;
use DDTrace\Integrations\ElasticSearch\ElasticSearchConfiguration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;
use DDTrace\Integrations\Eloquent\EloquentConfiguration;
use DDTrace\Integrations\Eloquent\EloquentIntegration;
use DDTrace\Integrations\Guzzle\GuzzleConfiguration;
use DDTrace\Integrations\Guzzle\GuzzleIntegration;
use DDTrace\Integrations\Laravel\LaravelConfiguration;
use DDTrace\Integrations\Laravel\LaravelIntegration;
use DDTrace\Integrations\Memcached\MemcachedConfiguration;
use DDTrace\Integrations\Memcached\MemcachedIntegration;
use DDTrace\Integrations\Mongo\MongoConfiguration;
use DDTrace\Integrations\Mongo\MongoIntegration;
use DDTrace\Integrations\Mysqli\MysqliConfiguration;
use DDTrace\Integrations\Mysqli\MysqliIntegration;
use DDTrace\Integrations\PDO\PDOConfiguration;
use DDTrace\Integrations\PDO\PDOIntegration;
use DDTrace\Integrations\Predis\PredisConfiguration;
use DDTrace\Integrations\Predis\PredisIntegration;
use DDTrace\Integrations\Symfony\SymfonyConfiguration;
use DDTrace\Integrations\Symfony\SymfonyIntegration;
use DDTrace\Integrations\Web\WebConfiguration;
use DDTrace\Integrations\Web\WebIntegration;
use DDTrace\Integrations\ZendFramework\ZendFrameworkConfiguration;
use DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration;

/**
 * DDTrace global configuration object.
 */
class Configuration extends AbstractConfiguration
{
    /**
     * @var array A registry to hold integration-level configuration objects.
     */
    private $integrationConfigurations = [];

    /**
     * Whether or not tracing is enabled.
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->boolValue('trace.enabled', true);
    }

    /**
     * Whether or not debug mode is enabled.
     *
     * @return bool
     */
    public function isDebugModeEnabled()
    {
        return $this->boolValue('trace.debug', false);
    }

    /**
     * Whether or not distributed tracing is enabled globally.
     *
     * @return bool
     */
    public function isDistributedTracingEnabled()
    {
        return $this->boolValue('distributed.tracing', true);
    }

    /**
     * Whether or not priority sampling is enabled globally.
     *
     * @return bool
     */
    public function isPrioritySamplingEnabled()
    {
        return $this->isDistributedTracingEnabled()
            && $this->boolValue('priority.sampling', true);
    }

    /**
     * Whether or not also unfinished spans should be finished (and thus sent) when tracer is flushed.
     * Motivation: We had users reporting that in some cases they have manual end-points that `echo` some content and
     * then just `exit(0)` at the end of action's method. While the shutdown hook that flushes traces would still be
     * called, many spans would be unfinished and thus discarded. With this option enabled spans are automatically
     * finished (if not finished yet) when the tracer is flushed.
     *
     * @return bool
     */
    public function isAutofinishSpansEnabled()
    {
        return $this->boolValue('autofinish.spans', false);
    }

    /**
     * Returns the sampling rate provided by the user. Default: 1.0 (keep all).
     *
     * @return float
     */
    public function getSamplingRate()
    {
        return $this->floatValue('sampling.rate', 1.0, 0.0, 1.0);
    }

    /**
     * Whether or not a specific integration is enabled.
     *
     * @param string $name
     * @return bool
     */
    public function isIntegrationEnabled($name)
    {
        return $this->isEnabled() && !$this->inArray('integrations.disabled', $name);
    }

    /**
     * Returns the global tags to be set on all spans.
     */
    public function getGlobalTags()
    {
        return $this->associativeStringArrayValue('trace.global.tags');
    }

    /**
     * The name of the application.
     *
     * @param string $default
     * @return string
     */
    public function appName($default = '')
    {
        $appName = $this->stringValue('trace.app.name');
        if ($appName) {
            return $appName;
        }
        $appName = getenv('ddtrace_app_name');
        if (false !== $appName) {
            return trim($appName);
        }
        return $default;
    }

    /**
     * @return CurlConfiguration The curl integration configuration object
     */
    public function curl()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            CurlIntegration::NAME,
            'DDTrace\Integrations\Curl\CurlConfiguration'
        );
    }

    /**
     * @return ElasticSearchConfiguration The elastic search integration configuration object
     */
    public function elasticSearch()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            ElasticSearchIntegration::NAME,
            'DDTrace\Integrations\ElasticSearch\ElasticSearchConfiguration'
        );
    }

    /**
     * @return EloquentConfiguration The eloquent integration configuration object
     */
    public function eloquent()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            EloquentIntegration::NAME,
            'DDTrace\Integrations\Eloquent\EloquentConfiguration'
        );
    }

    /**
     * @return GuzzleConfiguration The guzzle integration configuration object
     */
    public function guzzle()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            GuzzleIntegration::NAME,
            'DDTrace\Integrations\Guzzle\GuzzleConfiguration'
        );
    }

    /**
     * @return LaravelConfiguration The laravel integration configuration object
     */
    public function laravel()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            LaravelIntegration::NAME,
            'DDTrace\Integrations\Laravel\LaravelConfiguration'
        );
    }

    /**
     * @return MemcachedConfiguration The memcached integration configuration object
     */
    public function memcached()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            MemcachedIntegration::NAME,
            'DDTrace\Integrations\Memcached\MemcachedConfiguration'
        );
    }

    /**
     * @return MongoConfiguration The mongo integration configuration object
     */
    public function mongo()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            MongoIntegration::NAME,
            'DDTrace\Integrations\Mongo\MongoConfiguration'
        );
    }

    /**
     * @return MysqliConfiguration The mysqli integration configuration object
     */
    public function mysqli()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            MysqliIntegration::NAME,
            'DDTrace\Integrations\Mysqli\MysqliConfiguration'
        );
    }

    /**
     * @return PDOConfiguration The pdo integration configuration object
     */
    public function pdo()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            PDOIntegration::NAME,
            'DDTrace\Integrations\Pdo\PdoConfiguration'
        );
    }

    /**
     * @return PredisConfiguration The predis integration configuration object
     */
    public function predis()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            PredisIntegration::NAME,
            'DDTrace\Integrations\Predis\PredisConfiguration'
        );
    }

    /**
     * @return SymfonyConfiguration The symfony integration configuration object
     */
    public function symfony()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            SymfonyIntegration::NAME,
            'DDTrace\Integrations\Symfony\SymfonyConfiguration'
        );
    }

    /**
     * @return WebConfiguration The generic web request integration configuration object
     */
    public function web()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            WebIntegration::NAME,
            'DDTrace\Integrations\Web\WebConfiguration'
        );
    }

    /**
     * @return ZendFrameworkConfiguration The zend framework integration configuration object
     */
    public function zendFramework()
    {
        return $this->retrieveOrCreateIntegrationConfiguration(
            ZendFrameworkIntegration::NAME,
            'DDTrace\Integrations\ZendFramework\ZendFrameworkConfiguration'
        );
    }

    /**
     * Returns an integration configuration from the registry or create an instance and store it in the registry.
     *
     * @param string $name
     * @param string $class
     * @return mixed
     */
    private function retrieveOrCreateIntegrationConfiguration($name, $class)
    {
        if (!isset($this->integrationConfigurations[$name])) {
            $this->integrationConfigurations[$name] = new $class();
        }

        return $this->integrationConfigurations[$name];
    }
}
