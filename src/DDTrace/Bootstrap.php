<?php

namespace DDTrace;

use DDTrace\Encoders\Json;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Transport\Http;

/**
 * Bootstrap the the datadog tracer.
 */
final class Bootstrap
{
    /**
     * @var bool
     */
    private static $bootstrapped = false;

    /**
     * Idempotent method to bootstrap the datadog tracer once.
     */
    public static function tracerOnce()
    {
        if (self::$bootstrapped) {
            return;
        }

        self::$bootstrapped = true;
        self::resetTracer();

        register_shutdown_function(function () {
            $tracer = GlobalTracer::get();
            $scopeManager = $tracer->getScopeManager();
            $scopeManager->close();
            $tracer->flush();
        });
    }

    /**
     * Bootstrap the tracer and load all the integrations.
     */
    public static function tracerAndIntegrations()
    {
        self::tracerOnce();
        IntegrationsLoader::load();
    }

    /**
     * Reset the singleton tracer providing a brand new instance.
     */
    public static function resetTracer()
    {
        $tracer = new Tracer(new Http(new Json()));
        GlobalTracer::set($tracer);
    }
}
