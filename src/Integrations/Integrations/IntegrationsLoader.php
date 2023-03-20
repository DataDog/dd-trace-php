<?php

namespace DDTrace\Integrations;

use DDTrace\Integrations\CakePHP\CakePHPIntegration;
use DDTrace\Integrations\CodeIgniter\V2\CodeIgniterIntegration;
use DDTrace\Integrations\Curl\CurlIntegration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;
use DDTrace\Integrations\Eloquent\EloquentIntegration;
use DDTrace\Integrations\Guzzle\GuzzleIntegration;
use DDTrace\Integrations\Laravel\LaravelIntegration;
use DDTrace\Integrations\Lumen\LumenIntegration;
use DDTrace\Integrations\Memcached\MemcachedIntegration;
use DDTrace\Integrations\Mongo\MongoIntegration;
use DDTrace\Integrations\Mysqli\MysqliIntegration;
use DDTrace\Integrations\Nette\NetteIntegration;
use DDTrace\Integrations\Pcntl\PcntlIntegration;
use DDTrace\Integrations\PDO\PDOIntegration;
use DDTrace\Integrations\Predis\PredisIntegration;
use DDTrace\Integrations\Slim\SlimIntegration;
use DDTrace\Integrations\Symfony\SymfonyIntegration;
use DDTrace\Integrations\Web\WebIntegration;
use DDTrace\Integrations\WordPress\WordPressIntegration;
use DDTrace\Integrations\Yii\YiiIntegration;
use DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration;
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

        if (\PHP_MAJOR_VERSION >= 7) {
            $this->integrations[PcntlIntegration::NAME] =
                '\DDTrace\Integrations\Pcntl\PcntlIntegration';
        }

        $this->integrations[CurlIntegration::NAME] =
            '\DDTrace\Integrations\Curl\CurlIntegration';
        $this->integrations[GuzzleIntegration::NAME] =
            '\DDTrace\Integrations\Guzzle\GuzzleIntegration';
        $this->integrations[LaravelIntegration::NAME] =
            '\DDTrace\Integrations\Laravel\LaravelIntegration';
        $this->integrations[MysqliIntegration::NAME] =
            '\DDTrace\Integrations\Mysqli\MysqliIntegration';

        // Add integrations as they support PHP 8
        if (\PHP_MAJOR_VERSION >= 8) {
            return;
        }

        $this->integrations[MongoIntegration::NAME] =
            '\DDTrace\Integrations\Mongo\MongoIntegration';

        // For PHP 7.0+ use C level deferred integration loader
        if (\PHP_MAJOR_VERSION < 7) {
            $this->integrations[CakePHPIntegration::NAME] =
                '\DDTrace\Integrations\CakePHP\CakePHPIntegration';
            $this->integrations[CodeIgniterIntegration::NAME] =
                '\DDTrace\Integrations\CodeIgniter\V2\CodeIgniterIntegration';
            $this->integrations[ElasticSearchIntegration::NAME] =
                '\DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration';
            $this->integrations[EloquentIntegration::NAME] =
                '\DDTrace\Integrations\Eloquent\EloquentIntegration';
            $this->integrations[LumenIntegration::NAME] =
                '\DDTrace\Integrations\Lumen\LumenIntegration';
            $this->integrations[MemcachedIntegration::NAME] =
                '\DDTrace\Integrations\Memcached\MemcachedIntegration';
            $this->integrations[PDOIntegration::NAME] =
                '\DDTrace\Integrations\PDO\PDOIntegration';
            $this->integrations[PredisIntegration::NAME] =
                '\DDTrace\Integrations\Predis\PredisIntegration';
            $this->integrations[SlimIntegration::NAME] =
                '\DDTrace\Integrations\Slim\SlimIntegration';
            $this->integrations[YiiIntegration::NAME] =
                '\DDTrace\Integrations\Yii\YiiIntegration';
            $this->integrations[NetteIntegration::NAME] =
                '\DDTrace\Integrations\Nette\NetteIntegration';
            $this->integrations[SymfonyIntegration::NAME] =
                '\DDTrace\Integrations\Symfony\SymfonyIntegration';
            $this->integrations[WordPressIntegration::NAME] =
                '\DDTrace\Integrations\WordPress\WordPressIntegration';
            $this->integrations[ZendFrameworkIntegration::NAME] =
                '\DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration';
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

        self::logDebug('Attempting integrations load; note: some integrations are only loaded on first usage');

        foreach ($this->integrations as $name => $class) {
            if (!\ddtrace_config_integration_enabled($name)) {
                self::logDebug('Integration {name} is disabled', ['name' => $name]);
                continue;
            }

            // If the integration has already been loaded, we don't need to reload it. On the other hand, with
            // auto-instrumentation this method may be called many times as the hook is the autoloader callback.
            // So we want to make sure that we do not load the same integration twice if not required.
            $integrationLoadingStatus = $this->getLoadingStatus($name);
            if (
                in_array(
                    $integrationLoadingStatus,
                    [Integration::LOADED, Integration::NOT_AVAILABLE]
                )
            ) {
                continue;
            }

            $integration = new $class();
            $this->loadings[$name] = $integration->init();
            self::logResult($name, $this->loadings[$name]);
        }
    }

    /**
     * Logs a proper message to report the status of an integration loading attempt.
     *
     * @param string $name
     * @param int $result
     */
    public static function logResult($name, $result)
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
        return isset($this->loadings[$integrationName])
            ? $this->loadings[$integrationName]
            : Integration::NOT_LOADED;
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
