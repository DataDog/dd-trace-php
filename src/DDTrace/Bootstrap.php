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
        if (PHP_VERSION_ID < 80000) {
            \DDTrace\hook_method('DDTrace\\Bootstrap', 'flushTracerShutdown', null, function () {
                $tracer = GlobalTracer::get();
                $scopeManager = $tracer->getScopeManager();
                $scopeManager->close();
                if (!\dd_trace_env_config('DD_TRACE_AUTO_FLUSH_ENABLED')) {
                    $tracer->flush();
                }
            });
        }

        if (\dd_trace_env_config('DD_TRACE_GENERATE_ROOT_SPAN') || PHP_VERSION_ID < 80000) {
            if (\dd_trace_env_config('DD_TRACE_GENERATE_ROOT_SPAN')) {
                self::initRootSpan($tracer);
            }
            register_shutdown_function(function () {
                /*
                * Register the shutdown handler during shutdown so that it is run after all the other shutdown handlers.
                * Doing this ensures:
                * 1) Calls in shutdown hooks will still be instrumented
                * 2) Fatal errors (or any zend_bailout) during flush will happen after the user's shutdown handlers
                * Note: Other code that implements this same technique will be run _after_ the tracer shutdown.
                */
                register_shutdown_function(function () {
                    // We wrap the call in a closure to prevent OPcache from skipping the call.
                    if (PHP_VERSION_ID < 80000) { // internal handling
                        Bootstrap::flushTracerShutdown();
                    } else {
                        $tracer = GlobalTracer::get();
                        // this also gets set when creating a root span, but may not have the latest up-to-date data
                        if (
                            'cli' !== PHP_SAPI && \ddtrace_config_url_resource_name_enabled()
                            && $rootScope = $tracer->getRootScope()
                        ) {
                            $tracer->addUrlAsResourceNameToSpan($rootScope->getSpan());
                        }
                        /*
                         * Having this priority sampling here is actually a bug (should happen after service name
                         * substitutions), but it was this way before the refactor, so let's fix this in a
                         * subsequent release, when we will have ported everything else to the extension.
                         */
                        $tracer->setPrioritySampling($tracer->getPrioritySampling());
                    }
                });
            });
        }
    }

    public static function flushTracerShutdown()
    {
        dd_trace_disable_in_request(); // Ensure no more calls are instrumented
        // Flushing happens in the sandboxed tracing closure after the call.
        // Return a value from runtime to prevent OPcache from skipping the call.
        return mt_rand();
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
            $span->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
            if (isset($_SERVER['REQUEST_METHOD'])) {
                $span->setTag(Tag::HTTP_METHOD, $_SERVER['REQUEST_METHOD']);
            }
            if (isset($_SERVER['REQUEST_URI'])) {
                $span->setTag(Tag::HTTP_URL, $_SERVER['REQUEST_URI']);
            }
            // Status code defaults to 200, will be later on changed when http_response_code will be called
            $span->setTag(Tag::HTTP_STATUS_CODE, 200);

            // Adding configured incoming request http headers
            foreach (Private_\util_extract_configured_headers_as_tags($httpHeaders, true) as $tag => $value) {
                $span->setTag($tag, $value);
            }

            if (PHP_VERSION_ID >= 80000) {
                foreach ($httpHeaders as $header => $value) {
                    if (stripos($header, Propagator::DEFAULT_ORIGIN_HEADER) === 0) {
                        add_global_tag(Tag::ORIGIN, $value);
                    }
                }
            }
        }
        $integration = WebIntegration::getInstance();
        $integration->addTraceAnalyticsIfEnabledLegacy($span);
        $span->setTag(Tag::SERVICE_NAME, \ddtrace_config_app_name($operationName));

        $rootSpan = $span;
        \DDTrace\hook_function('header', null, function ($args) use ($rootSpan) {
            if (isset($args[2])) {
                $parsedHttpStatusCode = $args[2];
            } elseif (isset($args[0])) {
                $parsedHttpStatusCode = Bootstrap::parseStatusCode($args[0]);
            }

            if (isset($parsedHttpStatusCode)) {
                $rootSpan->setTag(Tag::HTTP_STATUS_CODE, $parsedHttpStatusCode);
            }

            // Adding configured outgoing response http headers
            if (isset($args[0]) && \is_string($args[0])) {
                $headerParts = explode(':', $args[0], 2);
                if (count($headerParts) == 2) {
                    foreach (
                        Private_\util_extract_configured_headers_as_tags(
                            [$headerParts[0] => $headerParts[1]],
                            false
                        ) as $tag => $value
                    ) {
                        $rootSpan->setTag($tag, $value);
                    }
                }
            }
        });

        \DDTrace\hook_function('http_response_code', null, function ($args) use ($rootSpan) {
            if (isset($args[0]) && \is_numeric($code = $args[0])) {
                $rootSpan->setTag(Tag::HTTP_STATUS_CODE, $code);
            }
        });
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
