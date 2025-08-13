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
     * {@inheritdoc}
     */
    public static function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    /**
     * @return int
     */
    public static function init(): int
    {
        \DDTrace\hook_method(
            'Laravel\Lumen\Application',
            '__construct',
            static function () {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan !== null) {
                    $rootSpan->meta[Tag::COMPONENT] = LumenIntegration::NAME;
                    $rootSpan->meta[Tag::SPAN_KIND] = 'server';
                }
            }
        );

        \DDTrace\trace_method(
            'Laravel\Lumen\Application',
            'prepareRequest',
            static function (SpanData $span, $args) {
                $span->meta[Tag::COMPONENT] = LumenIntegration::NAME;

                $rootSpan = \DDTrace\root_span();
                $request = $args[0];
                $rootSpan->name = 'lumen.request';
                $rootSpan->service = \ddtrace_config_app_name(LumenIntegration::NAME);
                LumenIntegration::addTraceAnalyticsIfEnabled($rootSpan);
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
                $hook => static function (SpanData $span, $args) {
                    $rootSpan = \DDTrace\root_span();

                    $span->service = \ddtrace_config_app_name(LumenIntegration::NAME);
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
                        && !\dd_trace_env_config("DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED")
                        && \dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")
                    ) {
                        $rootSpan->resource = $rootSpan->meta[Tag::HTTP_METHOD] . ' ' . $resourceName;
                    }
                },
            ]
        );

        $exceptionRender = static function (SpanData $span, $args) {
            $span->service = \ddtrace_config_app_name(LumenIntegration::NAME);
            $span->type = 'web';
            if (count($args) < 1 || !\is_a($args[0], 'Throwable')) {
                return;
            }

            $span->meta[Tag::COMPONENT] = LumenIntegration::NAME;

            $rootSpan = \DDTrace\root_span();
            $exception = $args[0];
            $rootSpan->exception = $exception;
        };

        \DDTrace\trace_method('Laravel\Lumen\Application', 'handleUncaughtException', [$hook => $exceptionRender]);
        \DDTrace\trace_method('Laravel\Lumen\Application', 'sendExceptionToHandler', [$hook => $exceptionRender]);

        // View is rendered in laravel as the method name overlaps

        return Integration::LOADED;
    }
}
