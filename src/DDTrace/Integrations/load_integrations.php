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
    $sandboxed,
    $debug
) {
    if (!\ddtrace_config_integration_enabled($name)) {
        return;
    }

    if ($sandboxed) {
        $integration = new $class();
        $status = $integration->init();
    } else {
        $status = $class::load();
    }

    if ($debug) {
        _log_loading_result($name, $status);
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
    $debug = $globalConfig->isDebugModeEnabled();
    _do_load_integration(
        CodeIgniterSandboxedIntegration::NAME,
        '\DDTrace\Integrations\CodeIgniter\V2\CodeIgniterSandboxedIntegration',
        true,
        $debug
    );

    if (\PHP_MAJOR_VERSION > 5) {
        _do_load_integration(
            CurlSandboxedIntegration::NAME,
            '\DDTrace\Integrations\Curl\CurlSandboxedIntegration',
            true,
            $debug
        );
    }

    _do_load_integration(
        ElasticSearchSandboxedIntegration::NAME,
        '\DDTrace\Integrations\ElasticSearch\V1\ElasticSearchSandboxedIntegration',
        true,
        $debug
    );

    _do_load_integration(
        EloquentSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Eloquent\EloquentSandboxedIntegration',
        true,
        $debug
    );

    _do_load_integration(
        GuzzleSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Guzzle\GuzzleSandboxedIntegration',
        true,
        $debug
    );

    _do_load_integration(
        LaravelSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Laravel\LaravelSandboxedIntegration',
        true,
        $debug
    );

    _do_load_integration(
        MemcachedSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Memcached\MemcachedSandboxedIntegration',
        true,
        $debug
    );

    _do_load_integration(
        MongoSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Mongo\MongoSandboxedIntegration',
        true,
        $debug
    );

    _do_load_integration(
        MysqliSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Mysqli\MysqliSandboxedIntegration',
        true,
        $debug
    );

    _do_load_integration(
        PDOSandboxedIntegration::NAME,
        '\DDTrace\Integrations\PDO\PDOSandboxedIntegration',
        true,
        $debug
    );

    _do_load_integration(
        PredisSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Predis\PredisSandboxedIntegration',
        true,
        $debug
    );

    if (\PHP_MAJOR_VERSION > 5) {
        _do_load_integration(
            SymfonySandboxedIntegration::NAME,
            '\DDTrace\Integrations\Symfony\SymfonySandboxedIntegration',
            true,
            $debug
        );
    }

    _do_load_integration(
        WordPressSandboxedIntegration::NAME,
        '\DDTrace\Integrations\WordPress\WordPressSandboxedIntegration',
        true,
        $debug
    );
    _do_load_integration(
        YiiSandboxedIntegration::NAME,
        '\DDTrace\Integrations\Yii\YiiSandboxedIntegration',
        true,
        $debug
    );

    _do_load_integration(
        ZendFrameworkSandboxedIntegration::NAME,
        '\DDTrace\Integrations\ZendFramework\ZendFrameworkSandboxedIntegration',
        true,
        $debug
    );
} else {
    $loadingStatuses = [];
    $debug = $globalConfig->isDebugModeEnabled();

    _do_load_integration(
        CakePHPIntegration::NAME,
        '\DDTrace\Integrations\CakePHP\CakePHPIntegration',
        false,
        $debug
    );

    _do_load_integration(
        CurlIntegration::NAME,
        '\DDTrace\Integrations\Curl\CurlIntegration',
        false,
        $debug
    );

    _do_load_integration(
        ElasticSearchIntegration::NAME,
        '\DDTrace\Integrations\ElasticSearch\V1\ElasticSearchIntegration',
        false,
        $debug
    );

    _do_load_integration(
        EloquentIntegration::NAME,
        '\DDTrace\Integrations\Eloquent\EloquentIntegration',
        false,
        $debug
    );

    _do_load_integration(
        GuzzleIntegration::NAME,
        '\DDTrace\Integrations\Guzzle\GuzzleIntegration',
        false,
        $debug
    );

    _do_load_integration(
        LaravelIntegration::NAME,
        '\DDTrace\Integrations\Laravel\LaravelIntegration',
        false,
        $debug
    );

    _do_load_integration(
        LumenIntegration::NAME,
        '\DDTrace\Integrations\Lumen\LumenIntegration',
        false,
        $debug
    );
    _do_load_integration(
        MemcachedIntegration::NAME,
        '\DDTrace\Integrations\Memcached\MemcachedIntegration',
        false,
        $debug
    );
    _do_load_integration(
        MongoIntegration::NAME,
        '\DDTrace\Integrations\Mongo\MongoIntegration',
        false,
        $debug
    );

    _do_load_integration(
        MysqliIntegration::NAME,
        '\DDTrace\Integrations\Mysqli\MysqliIntegration',
        false,
        $debug
    );

    _do_load_integration(
        PDOIntegration::NAME,
        '\DDTrace\Integrations\PDO\PDOIntegration',
        false,
        $debug
    );

    _do_load_integration(
        PredisIntegration::NAME,
        '\DDTrace\Integrations\Predis\PredisIntegration',
        false,
        $debug
    );

    _do_load_integration(
        SlimIntegration::NAME,
        '\DDTrace\Integrations\Slim\SlimIntegration',
        false,
        $debug
    );

    _do_load_integration(
        SymfonyIntegration::NAME,
        '\DDTrace\Integrations\Symfony\SymfonyIntegration',
        false,
        $debug
    );

    _do_load_integration(
        WebIntegration::NAME,
        '\DDTrace\Integrations\Web\WebIntegration',
        false,
        $debug
    );

    _do_load_integration(
        ZendFrameworkIntegration::NAME,
        '\DDTrace\Integrations\ZendFramework\ZendFrameworkIntegration',
        false,
        $debug
    );
}
