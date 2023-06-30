<?php

namespace DDTrace\Integrations\Lumen;

use DDTrace\SpanData;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;

/**
 * Lumen Sandboxed integration
 */
class LumenIntegration extends Integration
{
    const NAME = 'lumen';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return false;
    }

    /**
     * @return int
     */
    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return Integration::NOT_LOADED;
        }

        $rootSpan = \DDTrace\root_span();

        if (null === $rootSpan) {
            return Integration::NOT_LOADED;
        }

        $rootSpan->meta[Tag::COMPONENT] = LumenIntegration::NAME;
        $rootSpan->meta[Tag::SPAN_KIND] = 'server';

        $integration = $this;
        $appName = \ddtrace_config_app_name(self::NAME);

        \DDTrace\trace_method(
            'Laravel\Lumen\Application',
            'prepareRequest',
            function (SpanData $span, $args) use ($rootSpan, $integration, $appName) {
                $request = $args[0];
                $rootSpan->name = 'lumen.request';
                $rootSpan->service = $appName;
                $integration->addTraceAnalyticsIfEnabled($rootSpan);
                $span->meta[Tag::COMPONENT] = LumenIntegration::NAME;

                if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                    $rootSpan->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize($request->getUri());
                }
                $rootSpan->meta[Tag::HTTP_METHOD] = $request->getMethod();
                return false;
            }
        );

        // convert to non-tracing API
        $hook = 'posthook';
        // Extracting resource name as in legacy integration
        \DDTrace\trace_method(
            'Laravel\Lumen\Application',
            'handleFoundRoute',
            [
                $hook => function (SpanData $span, $args) use ($rootSpan, $appName) {
                    $span->service = $appName;
                    $span->type = 'web';
                    if (count($args) < 1 || !\is_array($args[0])) {
                        return;
                    }
                    $routeInfo = $args[0];
                    $resourceName = null;
                    $span->meta[Tag::COMPONENT] = LumenIntegration::NAME;
                    if (isset($routeInfo[1]['uses'])) {
                        $action = $routeInfo[1]['uses'];
                        $rootSpan->meta['lumen.route.action'] = $action;
                        $span->meta['lumen.route.action'] = $action;
                    }
                    if (isset($routeInfo[1]['as'])) {
                        $routeAlias = $routeInfo[1]['as'];
                        $rootSpan->meta['lumen.route.name'] = $routeAlias;
                        $span->resource = $routeAlias;
                        $resourceName = $routeAlias;
                    }
                    if (
                        null !== $resourceName
                        && !\DDTrace\Util\Runtime::getBoolIni("datadog.trace.url_as_resource_names_enabled")
                        && (PHP_VERSION_ID < 70000 || dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING"))
                    ) {
                        $rootSpan->resource = $rootSpan->meta[Tag::HTTP_METHOD] . ' ' . $resourceName;
                    }
                },
            ]
        );

        $exceptionRender = function (SpanData $span, $args) use ($rootSpan, $appName, $integration) {
            $span->service = $appName;
            $span->type = 'web';
            if (count($args) < 1 || !\is_a($args[0], 'Throwable')) {
                return;
            }
            $exception = $args[0];
            $integration->setError($rootSpan, $exception);
            $span->meta[Tag::COMPONENT] = LumenIntegration::NAME;
        };

        \DDTrace\trace_method('Laravel\Lumen\Application', 'handleUncaughtException', [$hook => $exceptionRender]);
        \DDTrace\trace_method('Laravel\Lumen\Application', 'sendExceptionToHandler', [$hook => $exceptionRender]);

        // View is rendered in laravel as the method name overlaps

        return Integration::LOADED;
    }
}
