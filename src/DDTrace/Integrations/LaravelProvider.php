<?php

namespace DDTrace\Integrations;

use DDTrace\Encoders\Json;
use DDTrace\Integrations\Mysqli;
use DDTrace\Integrations\PDO;
use DDTrace\Tags;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Engines\CompilerEngine;
use OpenTracing\GlobalTracer;

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

        // Creates a tracer with default transport and default propagators
        $tracer = new Tracer(new Http(new Json()));

        // Sets a global tracer (singleton).
        GlobalTracer::set($tracer);

        // Create a trace span for every template rendered
        // public function get($path, array $data = array())
        dd_trace(CompilerEngine::class, 'get', function ($scope, $path, $data) {
            $scope = GlobalTracer::get()->startActiveSpan('laravel/view');

            $e = null;
            try {
                $result = $this->getModels($builder);
            } catch (\Exception $e) {
                $span->setError($e);
            }

            $scope->close();

            if ($e === null) {
                return $result;
            } else {
                throw $e;
            }
        });

        // Create a span that starts from when Laravel first boots (public/index.php)
        $scope = $tracer->startActiveSpan('bootstrap'/*, ['start_time' => LARAVEL_START]*/);
        $scope->getSpan()->setTag(Tags\SERVICE_NAME, $this->getAppName());

        // Name the scope when the route matches
        $this->app['events']->listen(RouteMatched::class, function (RouteMatched $routeMatched) use ($scope) {
            $span = $scope->getSpan();
            $span->setResource(Route::getCurrentRoute()->getActionName() . ' ' . Route::currentRouteName());
            $span->setTag('laravel.route.name', Route::currentRouteName());
            $span->setTag('laravel.route.action', Route::getCurrentRoute()->getActionName());
        });

        // Enable extension integrations
        Eloquent::load();
        PDO::load();
        Predis::load();

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
