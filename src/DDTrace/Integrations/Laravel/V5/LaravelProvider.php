<?php

namespace DDTrace\Integrations\Laravel\V5;

use DDTrace;
use DDTrace\Configuration;
use DDTrace\Encoders\Json;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\StartSpanOptionsFactory;
use DDTrace\Tags;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\Types;
use DDTrace\Util\TryCatchFinally;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Engines\CompilerEngine;
use DDTrace\GlobalTracer;

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

        // Creates a tracer with default transport and default encoders
        $tracer = new Tracer(new Http(new Json()));
        // Sets a global tracer (singleton). Also store it in the Laravel
        // container for easy Laravel-specific use.
        GlobalTracer::set($tracer);
        $this->app->instance('DDTrace\Tracer', $tracer);
    }

    /**  @inheritdoc */
    public function boot()
    {
        if (!Configuration::get()->isIntegrationEnabled(self::NAME)) {
            return;
        }

        $tracer = GlobalTracer::get();

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
                        $span->setTag(Tags\RESOURCE_NAME, get_class($this) . '::' . $handlerMethod);
                        $span->setTag(Tags\SPAN_TYPE, Types\WEB_SERVLET);
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
            $scope->getSpan()->setTag(Tags\SPAN_TYPE, Types\WEB_SERVLET);
            return TryCatchFinally::executePublicMethod($scope, $this, 'get', [$path, $data]);
        });

        $startSpanOptions = StartSpanOptionsFactory::createForWebRequest(
            $tracer,
            [
                'start_time' => defined('LARAVEL_START')
                    ? DDTrace\Time\fromMicrotime(LARAVEL_START)
                    : DDTrace\Time\now(),
            ],
            $this->app->make('request')->header()
        );

        // Create a span that starts from when Laravel first boots (public/index.php)
        $scope = $tracer->startActiveSpan('laravel.request', $startSpanOptions);
        $scope->getSpan()->setTag(Tags\SERVICE_NAME, $this->getAppName());
        $scope->getSpan()->setTag(Tags\SPAN_TYPE, Types\WEB_SERVLET);

        // Name the scope when the route matches
        $this->app['events']->listen(
            'Illuminate\Routing\Events\RouteMatched',
            function (RouteMatched $event) use ($scope) {
                $span = $scope->getSpan();
                $span->setTag(
                    Tags\RESOURCE_NAME,
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
            function (RequestHandled $event) use ($scope) {
                $span = $scope->getSpan();
                $span->setTag('http.status_code', $event->response->getStatusCode());
                try {
                    $user = auth()->user()->id;
                    $span->setTag('laravel.user', strlen($user) ? $user : '-');
                } catch (\Exception $e) {
                }
            }
        );

        // Enable other integrations
        IntegrationsLoader::load();

        // Flushes traces to agent.
        register_shutdown_function(function () use ($scope) {
            $scope->close();
            GlobalTracer::get()->flush();
        });
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
