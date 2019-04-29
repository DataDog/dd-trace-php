<?php

namespace DDTrace\Integrations\Lumen\V5;

use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Lumen\LumenIntegration;
use DDTrace\Tag;
use DDTrace\Type;

final class LumenIntegrationLoader
{
    public function load()
    {
        if (!$this->shouldLoad()) {
            return Integration::NOT_AVAILABLE;
        }

        $span = GlobalTracer::get()->getRootScope()->getSpan();
        $span->overwriteOperationName('lumen.request');
        $span->setTag(Tag::SERVICE_NAME, $this->getAppName());
        $span->setIntegration(LumenIntegration::getInstance());
        $span->setTraceAnalyticsCandidate();

        dd_trace('Laravel\Lumen\Application', 'parseIncomingRequest', function () use ($span) {
            $routeInfo = dd_trace_forward_call();
            list($method, $pathInfo) = $routeInfo;
            $span->setTag(Tag::HTTP_URL, $pathInfo);
            $span->setTag(Tag::HTTP_METHOD, $method);
            return $routeInfo;
        });

        dd_trace('Laravel\Lumen\Application', 'dispatch', function () use ($span) {
            $response = dd_trace_forward_call();
            $resourceName = 'unnamed_route';
            if (isset($this->currentRoute[1]['uses'])) {
                $span->setTag('lumen.route.action', $this->currentRoute[1]['uses']);
                $resourceName = $this->currentRoute[1]['uses'];
            }
            if (isset($this->currentRoute[1]['as'])) {
                $span->setTag('lumen.route.name', $this->currentRoute[1]['as']);
                $resourceName = $this->currentRoute[1]['as'];
            }
            $span->setTag(Tag::RESOURCE_NAME, $span->getTag(Tag::HTTP_METHOD) . ' ' . $resourceName);
            return $response;
        });

        dd_trace('Symfony\Component\HttpFoundation\Response', 'setStatusCode', function ($code) use ($span) {
            $span->setTag(Tag::HTTP_STATUS_CODE, $code);
            return dd_trace_forward_call();
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
                    $scope = GlobalTracer::get()->startIntegrationScopeAndSpan(
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

    /**
     * @return bool
     */
    private function shouldLoad()
    {
        if ('cli' === PHP_SAPI && 'dd_testing' !== getenv('APP_ENV')) {
            return false;
        }
        if (!Configuration::get()->isIntegrationEnabled(LumenIntegration::NAME)) {
            return false;
        }
        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Lumen integration.', E_USER_WARNING);
            return false;
        }
        return true;
    }

    private function getAppName()
    {
        return Configuration::get()->appName() ?: LumenIntegration::NAME;
    }
}
