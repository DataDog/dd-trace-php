<?php

namespace DDTrace\Integrations\Laravel\V4;

use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\Span;
use DDTrace\StartSpanOptionsFactory;
use DDTrace\Tag;
use DDTrace\Time;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\Type;
use DDTrace\Util\TryCatchFinally;
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

        $appName = $this->getAppName();
        $tracer = GlobalTracer::get();
        $this->app->instance('DDTrace\Tracer', $tracer);
        $self = $this;

        dd_trace('\Illuminate\Foundation\Application', 'handle', function () use ($appName, $tracer, $self) {
            $args = func_get_args();
            $request = $args[0];
            $startSpanOptions = StartSpanOptionsFactory::createForWebRequest(
                $tracer,
                [
                    'start_time' => Time::now(),
                ],
                $request->header()
            );

            // Create a span that starts from when Laravel first boots (public/index.php)
            $self->rootScope = $tracer->startActiveSpan('laravel.request', $startSpanOptions);

            $requestSpan = $self->rootScope->getSpan();
            $requestSpan->setTag(Tag::SERVICE_NAME, $appName);
            $requestSpan->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);

            $response = call_user_func_array([$this, 'handle'], $args);
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
            $args = func_get_args();
            list($route, $request) = $args;
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
            return call_user_func_array([$this, 'setStatusCode'], $args);
        });

        dd_trace('Illuminate\Routing\Route', 'run', function () {
            $scope = LaravelProvider::buildBaseScope('laravel.action', $this->uri);
            return TryCatchFinally::executePublicMethod($scope, $this, 'run', func_get_args());
        });

        dd_trace('Illuminate\View\View', 'render', function () {
            $scope = LaravelProvider::buildBaseScope('laravel.view.render', $this->view);
            return TryCatchFinally::executePublicMethod($scope, $this, 'render', func_get_args());
        });

        dd_trace('Illuminate\Events\Dispatcher', 'fire', function () {
            $args = func_get_args();
            $scope = LaravelProvider::buildBaseScope('laravel.event.handle', $args[0]);
            return TryCatchFinally::executePublicMethod($scope, $this, 'fire', $args);
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
        $scope = GlobalTracer::get()->startActiveSpan($operation);
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
        if ('cli' === PHP_SAPI && 'dd_testing' !== getenv('APP_ENV')) {
            return false;
        }
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
        $name = null;

        if (getenv('ddtrace_app_name')) {
            $name = getenv('ddtrace_app_name');
        } elseif (is_callable('config')) {
            $name = config('app.name');
        }

        return empty($name) ? 'laravel' : $name;
    }
}
