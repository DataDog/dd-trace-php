<?php

namespace DDTrace\Integrations\Laravel\V4;

use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\Span;
use DDTrace\Tag;
use DDTrace\Type;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * DataDog Laravel 4.2 tracing provider. Use by installing the dd-trace library:
 *
 * composer require datadog/dd-trace
 *
 * And then load the provider in config/app.php:
 *
 *     'providers' => array_merge(include(base_path('modules/system/providers.php')), [
 *        // 'Illuminate\Html\HtmlServiceProvider', // Example
 *
 *        'DDTrace\Integrations\Laravel\V4\LaravelProvider',
 *        'System\ServiceProvider',
 *   ]),
 */
class LaravelProvider extends ServiceProvider
{
    const NAME = 'laravel';

    /**
     * @var Span|null
     */
    public $rootScope;

    /** @inheritdoc */
    public function register()
    {
        if (!$this->shouldLoad()) {
            return;
        }

        $appName = self::getAppName();
        $tracer = GlobalTracer::get();
        $this->app->instance('DDTrace\Tracer', $tracer);
        $self = $this;

        dd_trace('Illuminate\Foundation\Application', 'handle', function () use ($appName, $tracer, $self) {
            // Create a span that starts from when Laravel first boots (public/index.php)
            $self->rootScope = $tracer->getRootScope();

            $requestSpan = $self->rootScope->getSpan();
            $requestSpan->overwriteOperationName('laravel.request');
            // Overwriting the default web integration
            $requestSpan->setIntegration(\DDTrace\Integrations\Laravel\LaravelIntegration::getInstance());
            $requestSpan->setTraceAnalyticsCandidate();
            $requestSpan->setTag(Tag::SERVICE_NAME, $appName);

            $response = dd_trace_forward_call();
            $requestSpan->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode());

            return $response;
        });
    }

    /** @inheritdoc */
    public function boot()
    {
        if (!$this->shouldLoad()) {
            return;
        }

        $self = $this;

        // Name the scope when the route matches
        $this->app['events']->listen('router.matched', function () use ($self) {
            list($route, $request) = func_get_args();
            $span = $self->rootScope->getSpan();

            $span->setTag(Tag::RESOURCE_NAME, $route->getActionName() . ' ' . Route::currentRouteName());
            $span->setTag('laravel.route.name', $route->getName());
            $span->setTag('laravel.route.action', $route->getActionName());
            $span->setTag(Tag::HTTP_METHOD, $request->method());
            $span->setTag(Tag::HTTP_URL, $request->url());
        });

        dd_trace('Symfony\Component\HttpFoundation\Response', 'setStatusCode', function () use ($self) {
            $args = func_get_args();
            $self->rootScope->getSpan()->setTag(Tag::HTTP_STATUS_CODE, $args[0]);
            return dd_trace_forward_call();
        });

        dd_trace('Illuminate\Routing\Route', 'run', function () {
            $scope = LaravelProvider::buildBaseScope('laravel.action', $this->uri);
            return include __DIR__ . '/../../../try_catch_finally.php';
        });

        dd_trace('Illuminate\View\View', 'render', function () {
            $scope = LaravelProvider::buildBaseScope('laravel.view.render', $this->view);
            return include __DIR__ . '/../../../try_catch_finally.php';
        });

        dd_trace('Illuminate\Events\Dispatcher', 'fire', function () {
            $args = func_get_args();
            $scope = LaravelProvider::buildBaseScope('laravel.event.handle', $args[0]);
            return include __DIR__ . '/../../../try_catch_finally.php';
        });
    }

    /**
     * Starts a basic scope object with the common info required by all the resources.
     *
     * @param string $operation
     * @param string $resource
     * @return \DDTrace\Contracts\Scope
     */
    public static function buildBaseScope($operation, $resource)
    {
        $scope = GlobalTracer::get()->startIntegrationScopeAndSpan(
            \DDTrace\Integrations\Laravel\LaravelIntegration::getInstance(),
            $operation
        );
        $span = $scope->getSpan();
        $span->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
        $span->setTag(Tag::SERVICE_NAME, self::getAppName());
        $span->setTag(Tag::RESOURCE_NAME, $resource);

        return $scope;
    }

    /**
     * @return bool
     */
    private function shouldLoad()
    {
        if (!Configuration::get()->isIntegrationEnabled(self::NAME)) {
            return false;
        }
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Laravel integration.', E_USER_WARNING);
            return false;
        }

        return true;
    }

    /**
     * Returns the configurable app name.
     *
     * @return array|false|\Illuminate\Config\Repository|mixed|null|string
     */
    private static function getAppName()
    {
        $name = Configuration::get()->appName();
        if ($name) {
            return $name;
        }
        if (is_callable('config')) {
            return config('app.name');
        }
        return 'laravel';
    }
}
