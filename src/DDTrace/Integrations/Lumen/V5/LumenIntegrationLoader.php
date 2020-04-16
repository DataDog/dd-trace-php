<?php

namespace DDTrace\Integrations\Lumen\V5;

use DDTrace\GlobalTracer;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Lumen\LumenIntegration;
use DDTrace\Tag;
use DDTrace\Type;
use Symfony\Component\HttpFoundation\Request;

final class LumenIntegrationLoader
{
    public function load()
    {
        if (!Integration::shouldLoad(LumenIntegration::NAME)) {
            return Integration::NOT_AVAILABLE;
        }

        $span = GlobalTracer::get()->getRootScope()->getSpan();
        $span->overwriteOperationName('lumen.request');
        $span->setTag(Tag::SERVICE_NAME, \ddtrace_config_app_name(LumenIntegration::NAME));
        $span->setIntegration(LumenIntegration::getInstance());
        $span->setTraceAnalyticsCandidate();

        // prepareRequest() was added in Lumen 5.2
        // https://github.com/laravel/lumen-framework/blob/5.2/src/Application.php#L440
        dd_trace('Laravel\Lumen\Application', 'prepareRequest', function (Request $request) use ($span) {
            $span->setTag(Tag::HTTP_URL, $request->getUri());
            $span->setTag(Tag::HTTP_METHOD, $request->getMethod());
            return dd_trace_forward_call();
        });

        dd_trace('Laravel\Lumen\Application', 'dispatch', function () use ($span) {
            $response = dd_trace_forward_call();
            $resourceName = null;
            if (isset($this->currentRoute[1]['uses'])) {
                $span->setTag('lumen.route.action', $this->currentRoute[1]['uses']);
                $resourceName = $this->currentRoute[1]['uses'];
            }
            if (isset($this->currentRoute[1]['as'])) {
                $span->setTag('lumen.route.name', $this->currentRoute[1]['as']);
                $resourceName = $this->currentRoute[1]['as'];
            }
            if (null !== $resourceName) {
                $span->setTag(Tag::RESOURCE_NAME, $span->getTag(Tag::HTTP_METHOD) . ' ' . $resourceName);
            }
            return $response;
        });

        dd_trace('Symfony\Component\HttpFoundation\Response', 'setStatusCode', function ($code) use ($span) {
            $span->setTag(Tag::HTTP_STATUS_CODE, $code);
            return dd_trace_forward_call();
        });

        // Trace views
        dd_trace('Illuminate\View\Engines\CompilerEngine', 'get', function () {
            $scope = GlobalTracer::get()->startIntegrationScopeAndSpan(
                LumenIntegration::getInstance(),
                'lumen.view'
            );
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
            return include __DIR__ . '/../../../try_catch_finally.php';
        });

        // Trace middleware
        $traceMiddleware = function ($middlewares) {
            foreach ($middlewares as $middleware) {
                // Ignore closures
                if ($middleware instanceof \Closure) {
                    continue;
                }
                if (is_object($middleware)) {
                    $middleware = get_class($middleware);
                }
                if (!is_string($middleware)) {
                    continue;
                }
                // Middleware can be passed parameters during registration, in the form
                // 'middleware_name_or_class:param1,param2', so we need to extract the real name/class from the
                // pipeline
                // See: https://laravel.com/docs/5.7/middleware#middleware-parameters
                $middleware = explode(':', $middleware)[0];
                dd_trace($middleware, 'handle', function () {
                    $tracer = GlobalTracer::get();
                    if ($tracer->limited()) {
                        return dd_trace_forward_call();
                    }
                    $scope = $tracer->startIntegrationScopeAndSpan(
                        LumenIntegration::getInstance(),
                        'lumen.pipeline.pipe'
                    );
                    $span = $scope->getSpan();
                    $span->setTag(Tag::RESOURCE_NAME, get_class($this) . '::handle');
                    $span->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
                    return include __DIR__ . '/../../../try_catch_finally.php';
                });
            }

            return dd_trace_forward_call();
        };
        dd_trace('Laravel\Lumen\Application', 'middleware', $traceMiddleware);
        dd_trace('Laravel\Lumen\Application', 'routeMiddleware', $traceMiddleware);

        return Integration::LOADED;
    }
}
