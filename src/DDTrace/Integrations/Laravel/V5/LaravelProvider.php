<?php

namespace DDTrace\Integrations\Laravel\V5;

use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\StartSpanOptionsFactory;
use DDTrace\Tag;
use DDTrace\Time;
use DDTrace\Type;
use DDTrace\Util\TryCatchFinally;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Engines\CompilerEngine;

/**
 * DataDog Laravel tracing provider. Use by installing the dd-trace library:
 *
 * composer require datadog/dd-trace
 *
 * And then load the provider in config/app.php:
 *
 *     'providers' => array_merge(include(base_path('modules/system/providers.php')), [
 *        // 'Illuminate\Html\HtmlServiceProvider', // Example
 *
 *        'DDTrace\Integrations\LaravelProvider',
 *        'System\ServiceProvider',
 *   ]),
 */
class LaravelProvider extends ServiceProvider
{
    const NAME = 'laravel';

    /**  @inheritdoc */
    public function register()
    {
        if (!Configuration::get()->isIntegrationEnabled(self::NAME)) {
            return;
        }

        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Laravel integration.', E_USER_WARNING);
            return;
        }

        if (getenv('APP_ENV') != 'dd_testing' && php_sapi_name() == 'cli') {
            return;
        }

        $this->app->instance('DDTrace\Tracer', GlobalTracer::get());
    }

    /**  @inheritdoc */
    public function boot()
    {
        if (!Configuration::get()->isIntegrationEnabled(self::NAME)) {
            return;
        }

        $appName = $this->getAppName();
        $tracer = GlobalTracer::get();
        $rootScope = null;

        dd_trace('Illuminate\Foundation\Http\Kernel', 'handle', function() use ($appName, $tracer, &$rootScope) {
            $args = func_get_args();
            $request = $args[0];

            $startSpanOptions = StartSpanOptionsFactory::createForWebRequest(
                $tracer,
                [
                    'start_time' => defined('LARAVEL_START')
                        ? Time::fromMicrotime(LARAVEL_START)
                        : Time::now(),
                ],
                $request->header()
            );

            // Create a span that starts from when Laravel first boots (public/index.php)
            $rootScope = $tracer->startActiveSpan('laravel.request', $startSpanOptions);
            $requestSpan = $rootScope->getSpan();
            $requestSpan->setTag(Tag::SERVICE_NAME, $appName);
            $requestSpan->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);

            $response = call_user_func_array([$this, 'handle'], $args);
            $requestSpan->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode());

            return $response;
        });

        // Trace middleware
        dd_trace('Illuminate\Pipeline\Pipeline', 'then', function () {
            $args = func_get_args();

            foreach ($this->pipes as $pipe) {
                // Pipes can be passed both as class to the pipeline and as instances
                if (is_string($pipe) || is_object($pipe)) {
                    if (is_string($pipe)) {
                        // Middleware can be passed parameters during registration, in the form
                        // 'middleware_name_or_class:param1,param2', so we need to extract the real name/class from the
                        // pipeline
                        // See: https://laravel.com/docs/5.7/middleware#middleware-parameters
                        $class = explode(':', $pipe)[0];
                    } else {
                        // Ignore closures
                        if ($pipe instanceof \Closure) {
                            continue;
                        }
                        // If an instance is passed instead of the class, than we need to know the class from it.
                        $class = get_class($pipe);
                    }

                    $handlerMethod = $this->method;
                    dd_trace($class, $handlerMethod, function () use ($handlerMethod) {
                        $args = func_get_args();
                        $scope = GlobalTracer::get()->startActiveSpan('laravel.pipeline.pipe');
                        $span = $scope->getSpan();
                        $span->setTag(Tag::RESOURCE_NAME, get_class($this) . '::' . $handlerMethod);
                        $span->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
                        return TryCatchFinally::executePublicMethod($scope, $this, $handlerMethod, $args);
                    });
                }
            }

            return call_user_func_array([$this, 'then'], $args);
        });

        // Create a trace span for every template rendered
        // public function get($path, array $data = array())
        dd_trace('Illuminate\View\Engines\CompilerEngine', 'get', function ($path, $data = array()) {
            $scope = GlobalTracer::get()->startActiveSpan('laravel.view');
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
            return TryCatchFinally::executePublicMethod($scope, $this, 'get', [$path, $data]);
        });

        // Name the scope when the route matches
        $this->app['events']->listen(
            'Illuminate\Routing\Events\RouteMatched',
            function (RouteMatched $event) use (&$rootScope) {
                $span = $rootScope->getSpan();
                $span->setTag(
                    Tag::RESOURCE_NAME,
                    $event->route->getActionName() . ' ' . (Route::currentRouteName() ?: 'unnamed_route')
                );
                $span->setTag('laravel.route.name', Route::currentRouteName());
                $span->setTag('laravel.route.action', $event->route->getActionName());
                $span->setTag('http.method', $event->request->method());
                $span->setTag('http.url', $event->request->url());
            }
        );

        $this->app['events']->listen(
            'Illuminate\Foundation\Http\Events\RequestHandled',
            function (RequestHandled $event) use (&$rootScope) {
                $span = $rootScope->getSpan();
                try {
                    $user = auth()->user()->id;
                    $span->setTag('laravel.user', strlen($user) ? $user : '-');
                } catch (\Exception $e) {
                }
            }
        );
    }

    private function getAppName()
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
