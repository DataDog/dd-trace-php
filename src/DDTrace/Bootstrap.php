<?php

namespace DDTrace;

use DDTrace\Encoders\Json;
use DDTrace\Http\Request;
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
        self::initRootSpan();

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
        GlobalTracer::set(
            new Tracer(new Http(new Json()))
        );
    }

    /**
     * Initialize the root span
     *
     * @return void
     */
    private static function initRootSpan()
    {
        $tracer = GlobalTracer::get();
        $options = ['start_time' => Time::now()];
        $startSpanOptions = 'cli' === PHP_SAPI
            ? StartSpanOptions::create($options)
            : StartSpanOptionsFactory::createForWebRequest(
                $tracer,
                $options,
                Request::getHeaders()
            );
        $operationName = 'cli' === PHP_SAPI ? 'cli.command' : 'web.request';
        $span = $tracer->startRootSpan($operationName, $startSpanOptions)->getSpan();
        $span->setTag(
            Tag::SERVICE_NAME,
            Configuration::get()->appName($operationName)
        );
        $span->setTag(
            Tag::SPAN_TYPE,
            'cli' === PHP_SAPI ? Type::CLI : Type::WEB_SERVLET
        );
        if ('cli' !== PHP_SAPI) {
            $span->setTag(Tag::HTTP_METHOD, $_SERVER['REQUEST_METHOD']);
            $span->setTag(Tag::HTTP_URL, $_SERVER['REQUEST_URI']);
        }
    }
}
