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
        self::resetTracer();
        self::initRootSpan();
        self::registerOpenTracing();

        register_shutdown_function(function () {
            dd_trace_disable_in_request(); //disable function tracing to speedup shutdown

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
        GlobalTracer::set(new Tracer());
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
        $operationName = 'cli' === PHP_SAPI ? basename($_SERVER['argv'][0]) : 'web.request';
        $span = $tracer->startRootSpan($operationName, $startSpanOptions)->getSpan();
        $span->setIntegration(WebIntegration::getInstance());
        $span->setTraceAnalyticsCandidate();
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
            // Status code defaults to 200, will be later on changed when http_response_code will be called
            $span->setTag(Tag::HTTP_STATUS_CODE, 200);
        }

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
