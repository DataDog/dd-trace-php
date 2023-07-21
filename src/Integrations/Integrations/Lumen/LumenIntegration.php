<?php

namespace DDTrace\Integrations\Lumen;

use DDTrace\SpanData;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;

use function DDTrace\root_span;

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

        \DDTrace\hook_method(
            'Laravel\Lumen\Application',
            '__construct',
            function () {
                $rootSpan = root_span();
                if ($rootSpan === null) {
                    $rootSpan->meta[Tag::COMPONENT] = LumenIntegration::NAME;
                    $rootSpan->meta[Tag::SPAN_KIND] = 'server';
                }
            }
        );

        $integration = $this;
        $appName = \ddtrace_config_app_name(self::NAME);

        \DDTrace\trace_method(
            'Laravel\Lumen\Application',
            'prepareRequest',
            function (SpanData $span, $args) use ($integration, $appName) {
                $span->meta[Tag::COMPONENT] = LumenIntegration::NAME;

                $rootSpan = root_span();
                if ($rootSpan !== null) {
                    $request = $args[0];
                    $rootSpan->name = 'lumen.request';
                    $rootSpan->service = $appName;
                    $integration->addTraceAnalyticsIfEnabled($rootSpan);
                    if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                        $rootSpan->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize($request->getUri());
                    }
                    $rootSpan->meta[Tag::HTTP_METHOD] = $request->getMethod();
                }

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
                $hook => function (SpanData $span, $args) use ($appName) {
                    $rootSpan = root_span();

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
                        if ($rootSpan !== null) {
                            $rootSpan->meta['lumen.route.action'] = $action;
                        }
                        $span->meta['lumen.route.action'] = $action;
                    }
                    if (isset($routeInfo[1]['as'])) {
                        $routeAlias = $routeInfo[1]['as'];
                        if ($rootSpan !== null) {
                            $rootSpan->meta['lumen.route.name'] = $routeAlias;
                        }
                        $span->resource = $routeAlias;
                        $resourceName = $routeAlias;
                    }
                    if (
                        null !== $rootSpan
                        && null !== $resourceName
                        && !\DDTrace\Util\Runtime::getBoolIni("datadog.trace.url_as_resource_names_enabled")
                        && (PHP_VERSION_ID < 70000 || dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING"))
                    ) {
                        $rootSpan->resource = $rootSpan->meta[Tag::HTTP_METHOD] . ' ' . $resourceName;
                    }
                },
            ]
        );

        $exceptionRender = function (SpanData $span, $args) use ($appName, $integration) {
            $span->service = $appName;
            $span->type = 'web';
            if (count($args) < 1 || !\is_a($args[0], 'Throwable')) {
                return;
            }

            $span->meta[Tag::COMPONENT] = LumenIntegration::NAME;

            $rootSpan = root_span();
            if ($rootSpan !== null) {
                $exception = $args[0];
                $integration->setError($rootSpan, $exception);
            }
        };

        \DDTrace\trace_method('Laravel\Lumen\Application', 'handleUncaughtException', [$hook => $exceptionRender]);
        \DDTrace\trace_method('Laravel\Lumen\Application', 'sendExceptionToHandler', [$hook => $exceptionRender]);

        // View is rendered in laravel as the method name overlaps

        return Integration::LOADED;
    }
}
