<?php

namespace DDTrace;

use DDTrace\Http\Request;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Integrations\Web\WebIntegration;
use DDTrace\Private_;

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

        $tracer = self::resetTracer();

        if (\dd_trace_env_config('DD_TRACE_GENERATE_ROOT_SPAN')) {
            self::initRootSpan($tracer);
            register_shutdown_function(function () {
                /*
                * Register the shutdown handler during shutdown so that it is run after all the other shutdown handlers.
                * Doing this ensures fatal errors (or any zend_bailout) during flush will happen after the user's
                 * shutdown handlers
                * Note: Other code that implements this same technique will be run _after_ the tracer shutdown.
                */
                register_shutdown_function(function () {
                    // We wrap the call in a closure to prevent OPcache from skipping the call.
                    $tracer = GlobalTracer::get();
                    /*
                     * Having this priority sampling here is actually a bug (should happen after service name
                     * substitutions), but it was this way before the refactor, so let's fix this in a
                     * subsequent release, when we will have ported everything else to the extension.
                     */
                    $tracer->setPrioritySampling($tracer->getPrioritySampling());
                });
            });
        }
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
     * @return Tracer
     */
    public static function resetTracer()
    {
        $tracer = new Tracer();
        GlobalTracer::set($tracer);
        return $tracer;
    }

    /**
     * Initialize the root span
     *
     * @param Tracer $tracer
     * @return void
     */
    private static function initRootSpan(Tracer $tracer)
    {
        $options = ['start_time' => Time::now()];
        if ('cli' === PHP_SAPI) {
            $operationName = isset($_SERVER['argv'][0]) ? basename($_SERVER['argv'][0]) : 'cli.command';
            $span = $tracer->startRootSpan(
                $operationName,
                StartSpanOptions::create($options)
            )->getSpan();
            $span->setTag(Tag::SPAN_TYPE, Type::CLI);
        } else {
            $operationName = 'web.request';
            $httpHeaders = Request::getHeaders();
            $span = $tracer->startRootSpan(
                $operationName,
                StartSpanOptionsFactory::createForWebRequest(
                    $tracer,
                    $options,
                    $httpHeaders
                )
            )->getSpan();
        }
        $integration = WebIntegration::getInstance();
        $integration->addTraceAnalyticsIfEnabledLegacy($span);

        // is reset during span init, we need to re-set it again here
        $span->setTag(Tag::SERVICE_NAME, \ddtrace_config_app_name($operationName));
    }

    /**
     * Parses the status code from a a standard header line such as: 'HTTP/1.1 201 Created'.
     * As part of a refactoring, methods `initRootSpan` and `parseStatusCode` can be moved to a specific generic web
     * request handler class.
     *
     * @param string $headersLine
     * @return int|null
     */
    public static function parseStatusCode($headersLine)
    {
        if (
            empty($headersLine)
            || !is_string($headersLine)
            || substr(strtoupper($headersLine), 0, 5) !== 'HTTP/'
        ) {
            return null;
        }

        // Parts MUST be separated by space based on Http Spec:
        // Header definition: https://tools.ietf.org/html/rfc2616#section-6.1
        // Space char (SP) definition: https://tools.ietf.org/html/rfc2616#section-2.2
        $parts = explode(' ', $headersLine);
        if (count($parts) < 2 || !is_numeric($parts[1])) {
            return null;
        }

        // Vase don https://tools.ietf.org/html/rfc2616#section-6.1 the status code MUST be numeric.
        return (int) $parts[1];
    }
}
