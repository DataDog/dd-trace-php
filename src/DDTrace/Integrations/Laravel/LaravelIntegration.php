<?php

namespace DDTrace\Integrations\Laravel;

use DDTrace\Contracts\Span;
use DDTrace\GlobalTracer;
use DDTrace\SpanData;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;

/**
 * The base Laravel integration which delegates loading to the appropriate integration version.
 */
class LaravelIntegration extends Integration
{
    const NAME = 'laravel';

    const UNNAMED_ROUTE = 'unnamed_route';

    /**
     * @var string
     */
    private $serviceName;

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

        $rootScope = GlobalTracer::get()->getRootScope();
        $rootSpan = null;

        if (null === $rootScope || null === ($rootSpan = $rootScope->getSpan())) {
            return Integration::NOT_LOADED;
        }

        $integration = $this;

        \DDTrace\trace_method(
            'Illuminate\Foundation\Application',
            'handle',
            function (SpanData $span, $args, $response) use ($rootSpan, $integration) {
                // Overwriting the default web integration
                $rootSpan->overwriteOperationName('laravel.request');
                $integration->addTraceAnalyticsIfEnabledLegacy($rootSpan);
                if (\method_exists($response, 'getStatusCode')) {
                    $rootSpan->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode());
                }
                $rootSpan->setTag(Tag::SERVICE_NAME, $integration->getServiceName());

                $span->name = 'laravel.application.handle';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                $span->resource = 'Illuminate\Foundation\Application@handle';
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Routing\Router',
            'findRoute',
            function (SpanData $span, $args, $route) use ($rootSpan, $integration) {
                if (null === $route) {
                    return false;
                }

                list($request) = $args;

                // Overwriting the default web integration
                $integration->addTraceAnalyticsIfEnabledLegacy($rootSpan);
                $routeName = $route->getName() ?: LaravelIntegration::UNNAMED_ROUTE;
                // Starting with PHP 7, unnamed routes have been given a randomly generated name that we need to
                // normalize:
                // https://github.com/laravel/framework/blob/7.x/src/Illuminate/Routing/AbstractRouteCollection.php#L227
                if (\substr($routeName, 0, 11) === "generated::") {
                    $routeName = LaravelIntegration::UNNAMED_ROUTE;
                }

                $rootSpan->setTag(
                    Tag::RESOURCE_NAME,
                    $route->getActionName() . ' ' . $routeName
                );

                $rootSpan->setTag('laravel.route.name', $routeName);
                $rootSpan->setTag('laravel.route.action', $route->getActionName());
                $rootSpan->setTag('http.url', $request->url());
                $rootSpan->setTag('http.method', $request->method());

                return false;
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Routing\Route',
            'run',
            function (SpanData $span) use ($integration) {
                $span->name = 'laravel.action';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                $span->resource = $this->uri;
            }
        );

        \DDTrace\trace_method(
            'Symfony\Component\HttpFoundation\Response',
            'setStatusCode',
            function (SpanData $span, $args) use ($rootSpan) {
                $rootSpan->setTag(Tag::HTTP_STATUS_CODE, $args[0]);
                return false;
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Events\Dispatcher',
            'fire',
            function (SpanData $span, $args) use ($integration) {
                $span->name = 'laravel.event.handle';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                $span->resource = $args[0];
            }
        );

        \DDTrace\trace_method('Illuminate\View\View', 'render', function (SpanData $span) use ($integration) {
            $span->name = 'laravel.view.render';
            $span->type = Type::WEB_SERVLET;
            $span->service = $integration->getServiceName();
            $span->resource = $this->view;
        });

        \DDTrace\trace_method(
            'Illuminate\View\Engines\CompilerEngine',
            'get',
            function (SpanData $span, $args) use ($integration, $rootSpan) {
                // This is used by both laravel and lumen. For consistency we rename it for lumen traces as otherwise
                // users would see a span changing name as they upgrade to the new version.
                $span->name = $integration->isLumen($rootSpan) ? 'lumen.view' : 'laravel.view';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                if (isset($args[0]) && \is_string($args[0])) {
                    $span->resource = $args[0];
                }
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Foundation\ProviderRepository',
            'load',
            function (SpanData $span) use ($rootSpan, $integration) {
                $serviceName = $integration->getServiceName();
                $span->name = 'laravel.provider.load';
                $span->type = Type::WEB_SERVLET;
                $span->service = $serviceName;
                $span->resource = 'Illuminate\Foundation\ProviderRepository::load';
                $rootSpan->overwriteOperationName('laravel.request');
                $rootSpan->setTag(Tag::SERVICE_NAME, $serviceName);
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Console\Application',
            '__construct',
            function () use ($rootSpan, $integration) {
                $rootSpan->overwriteOperationName('laravel.artisan');
                $rootSpan->setTag(
                    Tag::RESOURCE_NAME,
                    !empty($_SERVER['argv'][1]) ? 'artisan ' . $_SERVER['argv'][1] : 'artisan'
                );
                return false;
            }
        );

        \DDTrace\trace_method(
            'Symfony\Component\Console\Application',
            'renderException',
            function (SpanData $span, $args) use ($rootSpan) {
                $rootSpan->setError($args[0]);
                return false;
            }
        );

        return Integration::LOADED;
    }

    public function getServiceName()
    {
        if (!empty($this->serviceName)) {
            return $this->serviceName;
        }
        $this->serviceName = \ddtrace_config_app_name();
        if (empty($this->serviceName) && is_callable('config')) {
            $this->serviceName = config('app.name');
        }
        return $this->serviceName ?: 'laravel';
    }

    /**
     * Tells whether a span is a lumen request.
     *
     * @param Span $rootSpan
     * @return bool
     */
    public function isLumen(Span $rootSpan)
    {
        return $rootSpan->getOperationName() === 'lumen.request';
    }
}
