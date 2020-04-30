<?php

namespace DDTrace\Integrations\Laravel\V5;

use DDTrace\GlobalTracer;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Laravel\LaravelIntegration;
use DDTrace\Scope;
use DDTrace\Tag;
use DDTrace\Type;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

class LaravelIntegrationLoader
{
    const KERNEL_CLASS = 'dd_kernel_class';

    /**
     * @var Scope
     */
    public $rootScope;

    public function load()
    {
        if (!Integration::shouldLoad(LaravelIntegration::NAME)) {
            return Integration::NOT_AVAILABLE;
        }

        $kernelClass = null;
        $self = $this;

        dd_trace('Illuminate\Routing\Events\RouteMatched', '__construct', function () use ($self) {
            list($route, $request) = func_get_args();
            if ($self->rootScope) {
                $span = $self->rootScope->getSpan();
                // Overwriting the default web integration
                $span->setIntegration(LaravelIntegration::getInstance());
                $span->setTraceAnalyticsCandidate();
                $span->setTag(
                    Tag::RESOURCE_NAME,
                    $route->getActionName() . ' ' . (Route::currentRouteName() ?: 'unnamed_route')
                );
                $span->setTag('laravel.route.name', Route::currentRouteName());
                $span->setTag('laravel.route.action', $route->getActionName());
                $span->setTag('http.url', $request->url());
                $span->setTag('http.method', $request->method());
            }

            return dd_trace_forward_call();
        });

        dd_trace('Illuminate\Foundation\Http\Events\RequestHandled', '__construct', function () use ($self) {
            $span = $self->rootScope->getSpan();
            try {
                $user = auth()->user();
                if ($user instanceof Authenticatable) {
                    $span->setTag('laravel.user', $user->getAuthIdentifier());
                }
            } catch (\Exception $e) {
            }

            return dd_trace_forward_call();
        });

        dd_trace('Illuminate\Foundation\ProviderRepository', 'load', function (array $providers) use ($self) {
            $response = $this->load($providers);
            $self->traceRelevantMethods();
            return $response;
        });

        /**
         * Artisan traces
         */
        dd_trace('Illuminate\Console\Application', '__construct', function () {
            $span = GlobalTracer::get()->getRootScope()->getSpan();
            // Overwrite the default web integration
            $span->setIntegration(LaravelIntegration::getInstance());
            $span->overwriteOperationName('laravel.artisan');
            $span->setTag(
                Tag::RESOURCE_NAME,
                !empty($_SERVER['argv'][1]) ? 'artisan ' . $_SERVER['argv'][1] : 'artisan'
            );
            return dd_trace_forward_call();
        });

        dd_trace('Symfony\Component\Console\Application', 'renderException', function ($e) {
            $span = GlobalTracer::get()->getActiveSpan();
            $span->setError($e);
            return dd_trace_forward_call();
        });

        return Integration::LOADED;
    }

    public function traceRelevantMethods()
    {
        $self = $this;
        $appName = $this->getAppName();
        $tracer = GlobalTracer::get();

        // Create a span that starts from when Laravel first boots (public/index.php)
        $this->rootScope = $tracer->getRootScope();
        $requestSpan = $this->rootScope->getSpan();
        $requestSpan->overwriteOperationName('laravel.request');
        $requestSpan->setTag(Tag::SERVICE_NAME, $appName);

        // Trace middleware
        dd_trace('Illuminate\Pipeline\Pipeline', 'then', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

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
                    dd_trace($class, $handlerMethod, function () use ($tracer, $handlerMethod) {
                        $scope = $tracer->startIntegrationScopeAndSpan(
                            \DDTrace\Integrations\Laravel\LaravelIntegration::getInstance(),
                            'laravel.pipeline.pipe'
                        );
                        $span = $scope->getSpan();
                        $span->setTag(Tag::RESOURCE_NAME, get_class($this) . '::' . $handlerMethod);
                        $span->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
                        return include __DIR__ . '/../../../try_catch_finally.php';
                    });
                }
            }

            return dd_trace_forward_call();
        });

        // Create a trace span for every template rendered
        // public function get($path, array $data = array())
        dd_trace('Illuminate\View\Engines\CompilerEngine', 'get', function () {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan(
                LaravelIntegration::getInstance(),
                'laravel.view'
            );
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
            return include __DIR__ . '/../../../try_catch_finally.php';
        });

        dd_trace('Symfony\Component\HttpFoundation\Response', 'setStatusCode', function () use ($self) {
            $args = func_get_args();
            $self->rootScope->getSpan()->setTag(Tag::HTTP_STATUS_CODE, $args[0]);
            return dd_trace_forward_call();
        });
    }

    private function getAppName()
    {
        $name = \ddtrace_config_app_name();
        if ($name) {
            return $name;
        }

        if (is_callable('config')) {
            return config('app.name');
        }
        return 'laravel';
    }
}
