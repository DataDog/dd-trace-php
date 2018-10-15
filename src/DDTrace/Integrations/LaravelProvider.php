<?php

namespace DDTrace\Integrations;

use DDTrace;
use DDTrace\Encoders\Json;
use DDTrace\Tags;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Engines\CompilerEngine;
use OpenTracing\GlobalTracer;

use function DDTrace\Time\fromMicrotime;

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
    public function register()
    {
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Laravel integration.', E_USER_WARNING);
            return;
        }

        if (php_sapi_name() == 'cli') {
            return;
        }

        // Creates a tracer with default transport and default encoders
        $tracer = new Tracer(new Http(new Json()));

        // Sets a global tracer (singleton). Also store it in the Laravel
        // container for easy Laravel-specific use.
        GlobalTracer::set($tracer);
        $this->app->instance(Tracer::class, $tracer);

        // Trace middleware
        dd_trace(Pipeline::class, 'through', function ($pipes) {
            foreach ($pipes as $pipe) {
                dd_trace($pipe, 'handle', function (...$args) {
                    $scope = GlobalTracer::get()->startActiveSpan('laravel.middleware');
                    $span = $scope->getSpan();
                    $span->setResource(get_class($this));

                    try {
                        return $this->handle(...$args);
                    } catch (\Exception $e) {
                        $span->setError($e);
                        throw $e;
                    } finally {
                        $scope->close();
                    }
                });
            }
            return $this->through($pipes);
        });

        // Create a trace span for every template rendered
        // public function get($path, array $data = array())
        dd_trace(CompilerEngine::class, 'get', function ($path, $data = array()) {
            $scope = GlobalTracer::get()->startActiveSpan('laravel.view');

            try {
                return $this->get($builder);
            } catch (\Exception $e) {
                $scope->getSpan()->setError($e);
                throw $e;
            } finally {
                $scope->close();
            }
        });

        // Create a span that starts from when Laravel first boots (public/index.php)
        $scope = $tracer->startActiveSpan('laravel.request', ['start_time' => fromMicrotime(LARAVEL_START)]);
        $scope->getSpan()->setTag(Tags\SERVICE_NAME, $this->getAppName());

        // Name the scope when the route matches
        $this->app['events']->listen(RouteMatched::class, function (RouteMatched $event) use ($scope) {
            $span = $scope->getSpan();
            $span->setResource($event->route->getActionName() . ' ' . Route::currentRouteName());
            $span->setTag('laravel.route.name', Route::currentRouteName());
            $span->setTag('laravel.route.action', $event->route->getActionName());
            $span->setTag('http.method', $event->request->method());
            $span->setTag('http.url', $event->request->url());
        });

        $this->app['events']->listen(RequestHandled::class, function (RequestHandled $event) use ($scope) {
            $span = $scope->getSpan();
            $span->setTag('http.status_code', $event->response->status());
            try {
                $span->setTag('laravel.user', auth()->user()->id ?? '-');
            } catch (\Exception $e) {
            }
        });

        // Enable extension integrations
        Eloquent::load();
        if (class_exists('Memcached')) {
            Memcached::load();
        }
        PDO::load();
        if (class_exists('Predis\Client')) {
            Predis::load();
        }

        // Flushes traces to agent.
        register_shutdown_function(function () use ($scope) {
            $scope->close();
            GlobalTracer::get()->flush();
        });
    }

    private function getAppName()
    {
        if (isset($_ENV['ddtrace_app_name'])) {
            return $_ENV['ddtrace_app_name'];
        } elseif (is_callable('config')) {
            return config('app.name');
        } else {
            return 'symfony';
        }
    }
}
