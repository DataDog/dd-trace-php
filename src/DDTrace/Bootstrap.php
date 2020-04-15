<?php

namespace DDTrace;

use DDTrace\Http\Request;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Integrations\Web\WebIntegration;

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
        self::registerOpenTracing();

        $flushTracer = function () {
            dd_trace_disable_in_request(); //disable function tracing to speedup shutdown

            $tracer = GlobalTracer::get();
            $scopeManager = $tracer->getScopeManager();
            $scopeManager->close();
            if (!\dd_trace_env_config('DD_TRACE_AUTO_FLUSH_ENABLED')) {
                $tracer->flush();
            }
        };

        // Sandbox API is not supported on PHP 5.4
        if (PHP_VERSION_ID < 50500) {
            if (\dd_trace_env_config('DD_TRACE_GENERATE_ROOT_SPAN')) {
                self::initRootSpan($tracer);
                register_shutdown_function($flushTracer);
            }
            return;
        }
        dd_trace_method('DDTrace\\Bootstrap', 'flushTracerShutdown', [
            'instrument_when_limited' => 1,
            'posthook' => $flushTracer,
        ]);

        if (\dd_trace_env_config('DD_TRACE_GENERATE_ROOT_SPAN')) {
            self::initRootSpan($tracer);
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
                    Bootstrap::flushTracerShutdown();
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
     * Replace the OT tracer with a wrapper containing the datadog tracer.
     */
    private static function registerOpenTracing()
    {
        dd_trace('OpenTracing\GlobalTracer', 'get', function () {
            $original = \OpenTracing\GlobalTracer::get();

            if (is_a($original, 'DDTrace\OpenTracer')) {
                return $original;
            }

            $otWrapper = new \DDTrace\OpenTracer\Tracer(GlobalTracer::get());
            \OpenTracing\GlobalTracer::set($otWrapper);

            return $otWrapper;
        });
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
            $span = $tracer->startRootSpan(
                $operationName,
                StartSpanOptionsFactory::createForWebRequest(
                    $tracer,
                    $options,
                    Request::getHeaders()
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
        }
        $span->setIntegration(WebIntegration::getInstance());
        $span->setTraceAnalyticsCandidate();
        $span->setTag(Tag::SERVICE_NAME, \ddtrace_config_app_name($operationName));

        dd_trace('header', function () use ($span) {
            $args = func_get_args();

            // header ( string $header [, bool $replace = TRUE [, int $http_response_code ]] ) : void
            $argsCount = count($args);

            $parsedHttpStatusCode = null;
            if ($argsCount === 1) {
                $result = header($args[0]);
                $parsedHttpStatusCode = Bootstrap::parseStatusCode($args[0]);
            } elseif ($argsCount === 2) {
                $result = header($args[0], $args[1]);
                $parsedHttpStatusCode = Bootstrap::parseStatusCode($args[0]);
            } else {
                $result = header($args[0], $args[1], $args[2]);
                // header() function can override the current status code
                $parsedHttpStatusCode = $args[2] === null ? Bootstrap::parseStatusCode($args[0]) : $args[2];
            }

            if (null !== $parsedHttpStatusCode) {
                $span->setTag(Tag::HTTP_STATUS_CODE, $parsedHttpStatusCode);
            }

            return $result;
        });

        dd_trace('http_response_code', function () use ($span) {
            $args = func_get_args();
            if (isset($args[0])) {
                $httpStatusCode = $args[0];

                if (is_numeric($httpStatusCode)) {
                    $span->setTag(Tag::HTTP_STATUS_CODE, $httpStatusCode);
                }
            }

            return dd_trace_forward_call();
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
