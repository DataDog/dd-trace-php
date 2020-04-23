<?php

namespace DDTrace\Integrations;

use DDTrace\Configuration;
use DDTrace\Integrations\CakePHP\CakePHPIntegration;
use DDTrace\Integrations\CodeIgniter\V2\CodeIgniterSandboxedIntegration;
use DDTrace\Integrations\Curl\CurlIntegration;
use DDTrace\Integrations\Curl\CurlSandboxedIntegration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration;
use DDTrace\Integrations\ElasticSearch\V1\ElasticSearchSandboxedIntegration;
use DDTrace\Integrations\Eloquent\EloquentIntegration;
use DDTrace\Integrations\Eloquent\EloquentSandboxedIntegration;
use DDTrace\Integrations\Guzzle\GuzzleIntegration;
use DDTrace\Integrations\Guzzle\GuzzleSandboxedIntegration;
use DDTrace\Integrations\Laravel\LaravelIntegration;
use DDTrace\Integrations\Laravel\LaravelSandboxedIntegration;
use DDTrace\Integrations\Lumen\LumenIntegration;
use DDTrace\Integrations\Memcached\MemcachedIntegration;
use DDTrace\Integrations\Memcached\MemcachedSandboxedIntegration;
use DDTrace\Integrations\Mongo\MongoIntegration;
use DDTrace\Integrations\Mongo\MongoSandboxedIntegration;
use DDTrace\Integrations\Mysqli\MysqliIntegration;
use DDTrace\Integrations\Mysqli\MysqliSandboxedIntegration;
use DDTrace\Integrations\PDO\PDOIntegration;
use DDTrace\Integrations\PDO\PDOSandboxedIntegration;
use DDTrace\Integrations\Predis\PredisIntegration;
use DDTrace\Integrations\Predis\PredisSandboxedIntegration;
use DDTrace\Integrations\Slim\SlimIntegration;
use DDTrace\Integrations\Symfony\SymfonyIntegration;
use DDTrace\Integrations\Symfony\SymfonySandboxedIntegration;
use DDTrace\Integrations\Web\WebIntegration;
use DDTrace\Integrations\WordPress\WordPressSandboxedIntegration;
use DDTrace\Integrations\Yii\YiiSandboxedIntegration;
use DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration;
use DDTrace\Integrations\ZendFramework\ZendFrameworkSandboxedIntegration;
use DDTrace\Log\Logger;

if (!\ddtrace_config_trace_enabled()) {
    return;
}

$globalConfig = Configuration::get();
if (!$globalConfig->isEnabled()) {
    return;
}

function _do_load_integration(
    $name,
    $class,
    &$loadingStatuses,
    $sandboxed,
    $debug
) {
    if (!\ddtrace_config_integration_enabled($name)) {
        return;
    }

    // If the integration has already been loaded, we don't need to reload it. On the other hand, with
    // auto-instrumentation this method may be called many times as the hook is the autoloader callback.
    // So we want to make sure that we do not load the same integration twice if not required.
    $integrationLoadingStatus = $loadingStatuses[$name];
    if (in_array($integrationLoadingStatus, [Integration::LOADED, Integration::NOT_AVAILABLE])) {
        return;
    }

    if ($sandboxed) {
        $integration = new $class();
        $loadingStatuses[$name] = $integration->init();
    } else {
        $loadingStatuses[$name] = $class::load();
    }

    if ($debug) {
        _log_loading_result($name, $loadingStatuses[$name]);
    }
}

function _log_loading_result($name, $result)
{
    if ($result === Integration::LOADED) {
        Logger::get()->debug('Loaded integration {name}', ['name' => $name]);
    } elseif ($result === Integration::NOT_AVAILABLE) {
        Logger::get()->debug('Integration {name} not available. New attempts WILL NOT be performed.', [
            'name' => $name,
        ]);
    } elseif ($result === Integration::NOT_LOADED) {
        Logger::get()->debug('Integration {name} not loaded. New attempts might be performed.', [
            'name' => $name,
        ]);
    } else {
        Logger::get()->debug('Invalid value returning by integration loader for {name}: {value}', [
            'name' => $name,
            'value' => $result,
        ]);
    }
}

if ($globalConfig->isSandboxEnabled()) {
    $loadingStatuses = [];
    $debug = $globalConfig->isDebugModeEnabled();

    _do_load_integration(
        CodeIgniterSandboxedIntegration::NAME,
        '\DDTrace\Integrations\CodeIgniter\V2\CodeIgniterSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );

    if (\PHP_MAJOR_VERSION > 5) {
        _do_load_integration(
            CurlSandboxedIntegration::NAME,
            '\DDTrace\Integrations\Curl\CurlSandboxedIntegration',
            $loadingStatuses,
            true,
            $debug
        );
    }

    _do_load_integration(
        ElasticSearchSandboxedIntegration::NAME,
        '\DDTrace\Integrations\ElasticSearch\V1\ElasticSearchSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );

    _do_load_integration(
        EloquentSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Eloquent\EloquentSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );

    _do_load_integration(
        GuzzleSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Guzzle\GuzzleSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );

    _do_load_integration(
        LaravelSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Laravel\LaravelSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );

    _do_load_integration(
        MemcachedSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Memcached\MemcachedSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );

    _do_load_integration(
        MongoSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Mongo\MongoSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );

    _do_load_integration(
        MysqliSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Mysqli\MysqliSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );

    _do_load_integration(
        PDOSandboxedIntegration::NAME,
        '\DDTrace\Integrations\PDO\PDOSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );

    _do_load_integration(
        PredisSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Predis\PredisSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );

    if (\PHP_MAJOR_VERSION > 5) {
        _do_load_integration(
            SymfonySandboxedIntegration::NAME,
            '\DDTrace\Integrations\Symfony\SymfonySandboxedIntegration',
            $loadingStatuses,
            true,
            $debug
        );
    }

    _do_load_integration(
        WordPressSandboxedIntegration::NAME,
        '\DDTrace\Integrations\WordPress\WordPressSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );
    _do_load_integration(
        YiiSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Yii\YiiSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );

    _do_load_integration(
        ZendFrameworkSandboxedIntegration::NAME,
        '\DDTrace\Integrations\ZendFramework\ZendFrameworkSandboxedIntegration',
        $loadingStatuses,
        true,
        $debug
    );
} else {
        $loadingStatuses = [];
    $debug = $globalConfig->isDebugModeEnabled();

    _do_load_integration(
        CakePHPIntegration::NAME,
        '\DDTrace\Integrations\CakePHP\CakePHPIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        CurlIntegration::NAME,
        '\DDTrace\Integrations\Curl\CurlIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        ElasticSearchIntegration::NAME,
        '\DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        EloquentIntegration::NAME,
        '\DDTrace\Integrations\Eloquent\EloquentIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        GuzzleIntegration::NAME,
        '\DDTrace\Integrations\Guzzle\GuzzleIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        LaravelIntegration::NAME,
        '\DDTrace\Integrations\Laravel\LaravelIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        LumenIntegration::NAME,
        '\DDTrace\Integrations\Lumen\LumenIntegration',
        $loadingStatuses,
        false,
        $debug
    );
    _do_load_integration(
        MemcachedIntegration::NAME,
        '\DDTrace\Integrations\Memcached\MemcachedIntegration',
        $loadingStatuses,
        false,
        $debug
    );
    _do_load_integration(
        MongoIntegration::NAME,
        '\DDTrace\Integrations\Mongo\MongoIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        MysqliIntegration::NAME,
        '\DDTrace\Integrations\Mysqli\MysqliIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        PDOIntegration::NAME,
        '\DDTrace\Integrations\PDO\PDOIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        PredisIntegration::NAME,
        '\DDTrace\Integrations\Predis\PredisIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        SlimIntegration::NAME,
        '\DDTrace\Integrations\Slim\SlimIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        SymfonyIntegration::NAME,
        '\DDTrace\Integrations\Symfony\SymfonyIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        WebIntegration::NAME,
        '\DDTrace\Integrations\Web\WebIntegration',
        $loadingStatuses,
        false,
        $debug
    );

    _do_load_integration(
        ZendFrameworkIntegration::NAME,
        '\DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration',
        $loadingStatuses,
        false,
        $debug
    );
}
