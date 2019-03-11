<?php

namespace DDTrace\Integrations\Laravel\V5;

use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Laravel\LaravelIntegration;
use DDTrace\Scope;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\TryCatchFinally;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Events\RouteMatched;
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
        if (!$this->shouldLoad()) {
            return Integration::NOT_AVAILABLE;
        }

        $kernelClass = null;
        $self = $this;

        dd_trace('Illuminate\Routing\Events\RouteMatched', '__construct', function () use ($self) {
            $args = func_get_args();

            list($route, $request) = $args;
            $span = $self->rootScope->getSpan();
            $span->setTag(
                Tag::RESOURCE_NAME,
                $route->getActionName() . ' ' . (Route::currentRouteName() ?: 'unnamed_route')
            );
            $span->setTag('laravel.route.name', Route::currentRouteName());
            $span->setTag('laravel.route.action', $route->getActionName());
            $span->setTag('http.url', $request->url());
            $span->setTag('http.method', $request->method());

            return call_user_func_array([$this, '__construct'], $args);
        });

        dd_trace('Illuminate\Foundation\Http\Events\RequestHandled', '__construct', function () use ($self) {
            $args = func_get_args();

            $span = $self->rootScope->getSpan();
            try {
                $user = auth()->user()->id;
                $span->setTag('laravel.user', strlen($user) ? $user : '-');
            } catch (\Exception $e) {
            }

            return call_user_func_array([$this, '__construct'], $args);
        });

        dd_trace('Illuminate\Foundation\ProviderRepository', 'load', function (array $providers) use ($self) {
            $response = $this->load($providers);
            $self->traceRelevantMethods();
            return $response;
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

        dd_trace('Symfony\Component\HttpFoundation\Response', 'setStatusCode', function () use ($self) {
            $args = func_get_args();
            $self->rootScope->getSpan()->setTag(Tag::HTTP_STATUS_CODE, $args[0]);
            return call_user_func_array([$this, 'setStatusCode'], $args);
        });
    }

    /**
     * @return bool
     */
    private function shouldLoad()
    {
        if ('cli' === PHP_SAPI && 'dd_testing' !== getenv('APP_ENV')) {
            return false;
        }
        if (!Configuration::get()->isIntegrationEnabled(LaravelIntegration::NAME)) {
            return false;
        }
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Laravel integration.', E_USER_WARNING);
            return false;
        }

        return true;
    }

    private function getAppName()
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
