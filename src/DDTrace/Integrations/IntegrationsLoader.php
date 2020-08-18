<?php

namespace DDTrace\Integrations;

use DDTrace\Integrations\CakePHP\CakePHPSandboxedIntegration;
use DDTrace\Integrations\CodeIgniter\V2\CodeIgniterSandboxedIntegration;
use DDTrace\Integrations\Curl\CurlSandboxedIntegration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchSandboxedIntegration;
use DDTrace\Integrations\Eloquent\EloquentSandboxedIntegration;
use DDTrace\Integrations\Guzzle\GuzzleSandboxedIntegration;
use DDTrace\Integrations\Laravel\LaravelSandboxedIntegration;
use DDTrace\Integrations\Lumen\LumenSandboxedIntegration;
use DDTrace\Integrations\Memcached\MemcachedIntegration;
use DDTrace\Integrations\Memcached\MemcachedSandboxedIntegration;
use DDTrace\Integrations\Mongo\MongoIntegration;
use DDTrace\Integrations\Mongo\MongoSandboxedIntegration;
use DDTrace\Integrations\Mysqli\MysqliIntegration;
use DDTrace\Integrations\Mysqli\MysqliSandboxedIntegration;
use DDTrace\Integrations\PDO\PDOIntegration;
use DDTrace\Integrations\PDO\PDOSandboxedIntegration;
use DDTrace\Integrations\PHPRedis\PHPRedisSandboxedIntegration;
use DDTrace\Integrations\Predis\PredisIntegration;
use DDTrace\Integrations\Predis\PredisSandboxedIntegration;
use DDTrace\Integrations\Slim\SlimIntegration;
use DDTrace\Integrations\Slim\SlimSandboxedIntegration;
use DDTrace\Integrations\Symfony\SymfonyIntegration;
use DDTrace\Integrations\Symfony\SymfonySandboxedIntegration;
use DDTrace\Integrations\Web\WebIntegration;
use DDTrace\Integrations\WordPress\WordPressSandboxedIntegration;
use DDTrace\Integrations\Yii\YiiSandboxedIntegration;
use DDTrace\Integrations\ZendFramework\ZendFrameworkSandboxedIntegration;
use DDTrace\Log\LoggingTrait;

/**
 * Loader for all integrations currently enabled.
 */
class IntegrationsLoader
{
    use LoggingTrait;

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
        WebIntegration::NAME => '\DDTrace\Integrations\Web\WebIntegration',
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
        // Sandboxed integrations get loaded with a feature flag
        if (\ddtrace_config_sandbox_enabled()) {
            $this->integrations[CakePHPSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\CakePHP\CakePHPSandboxedIntegration';
            $this->integrations[CodeIgniterSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\CodeIgniter\V2\CodeIgniterSandboxedIntegration';
            $this->integrations[CurlSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\Curl\CurlSandboxedIntegration';
            $this->integrations[ElasticSearchSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\ElasticSearch\V1\ElasticSearchSandboxedIntegration';
            $this->integrations[EloquentSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\Eloquent\EloquentSandboxedIntegration';
            $this->integrations[GuzzleSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\Guzzle\GuzzleSandboxedIntegration';
            $this->integrations[LaravelSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\Laravel\LaravelSandboxedIntegration';
            $this->integrations[LumenSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\Lumen\LumenSandboxedIntegration';
            $this->integrations[MemcachedSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\Memcached\MemcachedSandboxedIntegration';
            $this->integrations[MongoSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\Mongo\MongoSandboxedIntegration';
            $this->integrations[MysqliSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\Mysqli\MysqliSandboxedIntegration';
            $this->integrations[PDOSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\PDO\PDOSandboxedIntegration';
            if (\PHP_MAJOR_VERSION >= 7) {
                $this->integrations[PHPRedisSandboxedIntegration::NAME] =
                    '\DDTrace\Integrations\PHPRedis\PHPRedisSandboxedIntegration';
            }
            $this->integrations[PredisSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\Predis\PredisSandboxedIntegration';
            $this->integrations[SlimSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\Slim\SlimSandboxedIntegration';
            $this->integrations[SymfonySandboxedIntegration::NAME] =
                '\DDTrace\Integrations\Symfony\SymfonySandboxedIntegration';
            $this->integrations[WordPressSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\WordPress\WordPressSandboxedIntegration';
            $this->integrations[YiiSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\Yii\YiiSandboxedIntegration';
            $this->integrations[ZendFrameworkSandboxedIntegration::NAME] =
                '\DDTrace\Integrations\ZendFramework\ZendFrameworkSandboxedIntegration';
        }

        // For PHP 7.0+ use C level deferred integration loader
        if (\PHP_MAJOR_VERSION >= 7) {
            unset($this->integrations[ElasticSearchSandboxedIntegration::NAME]);
        }
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
        if (!extension_loaded('ddtrace')) {
            trigger_error(
                'Missing ddtrace extension. To disable tracing set env variable DD_TRACE_ENABLED=false',
                E_USER_WARNING
            );
            return;
        }

        if (!\ddtrace_config_trace_enabled()) {
            return;
        }

        self::logDebug('Attempting integrations load');

        foreach ($this->integrations as $name => $class) {
            if (!\ddtrace_config_integration_enabled($name)) {
                self::logDebug('Integration {name} is disabled', ['name' => $name]);
                continue;
            }

            // If the integration has already been loaded, we don't need to reload it. On the other hand, with
            // auto-instrumentation this method may be called many times as the hook is the autoloader callback.
            // So we want to make sure that we do not load the same integration twice if not required.
            $integrationLoadingStatus = $this->getLoadingStatus($name);
            if (in_array($integrationLoadingStatus, [Integration::LOADED, Integration::NOT_AVAILABLE])) {
                continue;
            }

            if (strpos($class, 'SandboxedIntegration') !== false) {
                $integration = new $class();
                $this->loadings[$name] = $integration->init();
            } else {
                $this->loadings[$name] = $class::load();
            }
            $this->logResult($name, $this->loadings[$name]);
        }
    }

    /**
     * Logs a proper message to report the status of an integration loading attempt.
     *
     * @param string $name
     * @param int $result
     */
    private function logResult($name, $result)
    {
        if ($result === Integration::LOADED) {
            self::logDebug('Loaded integration {name}', ['name' => $name]);
        } elseif ($result === Integration::NOT_AVAILABLE) {
            self::logDebug('Integration {name} not available. New attempts WILL NOT be performed.', [
                'name' => $name,
            ]);
        } elseif ($result === Integration::NOT_LOADED) {
            self::logDebug('Integration {name} not loaded. New attempts might be performed.', [
                'name' => $name,
            ]);
        } else {
            self::logError('Invalid value returning by integration loader for {name}: {value}', [
                'name' => $name,
                'value' => $result,
            ]);
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

    public static function reload()
    {
        self::$instance = null;
        self::load();
    }

    public function reset()
    {
        $this->integrations = [];
    }
}
