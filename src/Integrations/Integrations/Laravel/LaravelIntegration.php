<?php

namespace DDTrace\Integrations\Laravel;

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

        $rootSpan = \DDTrace\root_span();
        $rootSpan->meta[Tag::COMPONENT] = Integration::getName();

        if (null === $rootSpan) {
            return Integration::NOT_LOADED;
        }

        $integration = $this;

        \DDTrace\trace_method(
            'Illuminate\Foundation\Application',
            'handle',
            function (SpanData $span, $args, $response) use ($rootSpan, $integration) {
                // Overwriting the default web integration
                $rootSpan->name = 'laravel.request';
                $integration->addTraceAnalyticsIfEnabled($rootSpan);
                if (\method_exists($response, 'getStatusCode')) {
                    $rootSpan->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                }
                $rootSpan->service = $integration->getServiceName();

                $span->name = 'laravel.application.handle';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                $span->resource = 'Illuminate\Foundation\Application@handle';
                $span->meta[Tag::COMPONENT] = Integration::getName();
            }
        );

        \DDTrace\hook_method(
            'Illuminate\Routing\Router',
            'findRoute',
            null,
            function ($This, $scope, $args, $route) use ($rootSpan, $integration) {
                if (!isset($route)) {
                    return;
                }

                /** @var \Illuminate\Http\Request $request */
                list($request) = $args;

                // Overwriting the default web integration
                $integration->addTraceAnalyticsIfEnabled($rootSpan);
                $routeName = LaravelIntegration::normalizeRouteName($route->getName());

                $rootSpan->resource = $route->getActionName() . ' ' . $routeName;

                $rootSpan->meta['laravel.route.name'] = $routeName;
                $rootSpan->meta['laravel.route.action'] = $route->getActionName();

                if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                    $rootSpan->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize($request->fullUrl());
                }
                $rootSpan->meta[Tag::HTTP_METHOD] = $request->method();
                $rootSpan->meta[Tag::SPAN_KIND] = 'server';
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
                $span->meta[Tag::SPAN_KIND] = 'server';
                $span->meta[Tag::COMPONENT] = Integration::getName();
            }
        );

        \DDTrace\hook_method(
            'Illuminate\Http\Response',
            'send',
            function ($This, $scope, $args) use ($rootSpan, $integration) {
                if (isset($This->exception) && $This->getStatusCode() >= 500) {
                    $integration->setError($rootSpan, $This->exception);
                }
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
                $span->meta[Tag::SPAN_KIND] = 'server';
                $span->meta[Tag::COMPONENT] = Integration::getName();
            }
        );

        \DDTrace\trace_method('Illuminate\View\View', 'render', function (SpanData $span) use ($integration) {
            $span->name = 'laravel.view.render';
            $span->type = Type::WEB_SERVLET;
            $span->service = $integration->getServiceName();
            $span->resource = $this->view;
            $span->meta[Tag::COMPONENT] = Integration::getName();
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
                $span->meta[Tag::COMPONENT] = Integration::getName();
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
                $rootSpan->name = 'laravel.request';
                $rootSpan->service = $serviceName;
                $span->meta[Tag::SPAN_KIND] = 'server';
                $span->meta[Tag::COMPONENT] = Integration::getName();
            }
        );

        \DDTrace\hook_method(
            'Illuminate\Console\Application',
            '__construct',
            function () use ($rootSpan, $integration) {
                $rootSpan->name = 'laravel.artisan';
                $rootSpan->resource = !empty($_SERVER['argv'][1]) ? 'artisan ' . $_SERVER['argv'][1] : 'artisan';
            }
        );

        // renderException is since Symfony 4.4, use "renderThrowable()" instead
        // Used by Laravel < v7.0
        \DDTrace\hook_method(
            'Symfony\Component\Console\Application',
            'renderException',
            function ($This, $scope, $args) use ($rootSpan, $integration) {
                $integration->setError($rootSpan, $args[0]);
            }
        );

        // Used by Laravel > v7.0
        // More details: https://github.com/laravel/framework/commit/f81b6ed01fb60580ade8c7fb4386aff4cb4d7719
        \DDTrace\hook_method(
            'Symfony\Component\Console\Application',
            'renderThrowable',
            function ($This, $scope, $args) use ($rootSpan, $integration) {
                $integration->setError($rootSpan, $args[0]);
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
     * @param SpanData $rootSpan
     * @return bool
     */
    public function isLumen(SpanData $rootSpan)
    {
        return $rootSpan->name === 'lumen.request';
    }

    /**
     * @param mixed $routeName
     * @return string
     */
    public static function normalizeRouteName($routeName)
    {
        if (!\is_string($routeName)) {
            return LaravelIntegration::UNNAMED_ROUTE;
        }

        $routeName = \trim($routeName);
        if ($routeName === '') {
            return LaravelIntegration::UNNAMED_ROUTE;
        }

        // Starting with PHP 7, unnamed routes have been given a randomly generated name that we need to
        // normalize:
        // https://github.com/laravel/framework/blob/7.x/src/Illuminate/Routing/AbstractRouteCollection.php#L227
        //
        // It can also be prefixed with domain name when caching is specified as Route::domain()->group(...);
        if (\strpos($routeName, 'generated::') !== false) {
            return LaravelIntegration::UNNAMED_ROUTE;
        }

        return $routeName;
    }
}
