<?php

namespace DDTrace\Integrations\CakePHP\V2;

use CakeRequest;
use DDTrace\Integrations\CakePHP\CakePHPIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use Router;

class CakePHPIntegrationLoader
{
    // CakePHP v2.x - we don't need to check for v3 since it does not have \Dispatcher or \ShellDispatcher
    public static function load()
    {
        if (!defined('CAKE_CORE_INCLUDE_PATH')) {
            return Integration::NOT_AVAILABLE;
        }

        \DDTrace\hook_method('App', 'init', CakePHPIntegration::$setRootSpanInfoFn);
        \DDTrace\hook_method('Dispatcher', '__construct', CakePHPIntegration::$setRootSpanInfoFn);

        \DDTrace\trace_method(
            'Controller',
            'invokeAction',
            function (SpanData $span, array $args) {
                $span->name = $span->resource = 'Controller.invokeAction';
                $span->type = Type::WEB_SERVLET;
                $span->service = CakePHPIntegration::$appName;
                $span->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;

                $request = $args[0];
                if (!$request instanceof CakeRequest) {
                    return;
                }

                $rootSpan = \DDTrace\root_span();

                if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")) {
                    $rootSpan->resource =
                        $_SERVER['REQUEST_METHOD'] . ' ' . $this->name . 'Controller@' . $request->params['action'];
                }

                if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                    $rootSpan->meta[Tag::HTTP_URL] = Router::url($request->here, true)
                        . Normalizer::sanitizedQueryString();
                }
                $rootSpan->meta['cakephp.route.controller'] = $request->params['controller'];
                $rootSpan->meta['cakephp.route.action'] = $request->params['action'];
                if (isset($request->params['plugin'])) {
                    $rootSpan->meta['cakephp.plugin'] = $request->params['plugin'];
                }
            }
        );

        // This only traces the default exception renderer
        // Remove this when error tracking is added
        // Other possible places to trace
        // - ErrorHandler::handleException()
        // - Controller::appError()
        // - Exception.handler
        // - Exception.renderer
        \DDTrace\hook_method(
            'ExceptionRenderer',
            '__construct',
            CakePHPIntegration::$handleExceptionFn
        );

        \DDTrace\hook_method(
            'CakeResponse',
            'statusCode',
            null,
            CakePHPIntegration::$setStatusCodeFn
        );

        // Create a trace span for every template rendered
        \DDTrace\trace_method('View', 'render', function (SpanData $span) {
            $span->name = 'cakephp.view';
            $span->type = Type::WEB_SERVLET;
            $file = $this->viewPath . '/' . $this->view . $this->ext;
            $span->resource = $file;
            $span->meta = ['cakephp.view' => $file];
            $span->service = CakePHPIntegration::$appName;
            $span->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;
        });

        \DDTrace\hook_method(
            'CakeRoute',
            'parse',
            null,
            CakePHPIntegration::$parseRouteFn
        );

        return Integration::LOADED;
    }
}
