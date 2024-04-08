<?php

namespace DDTrace\Integrations\CakePHP\V3;

use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use DDTrace\Integrations\CakePHP\CakePHPIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;

class CakePHPIntegrationLoader
{
    public function load($integration)
    {
        $integration->rootSpan = null;

        $setRootSpanInfoFn = function () use ($integration) {
            $rootSpan = \DDTrace\root_span();
            if ($rootSpan === null) {
                return;
            }

            $integration->appName = \ddtrace_config_app_name(CakePHPIntegration::NAME);
            $integration->rootSpan = $rootSpan;
            $integration->addTraceAnalyticsIfEnabled($integration->rootSpan);
            $integration->rootSpan->service = $integration->appName;
            if ('cli' === PHP_SAPI) {
                $integration->rootSpan->name = 'cakephp.console';
                $integration->rootSpan->resource =
                    !empty($_SERVER['argv'][1]) ? 'cake_console ' . $_SERVER['argv'][1] : 'cake_console';
            } else {
                $integration->rootSpan->name = 'cakephp.request';
                $integration->rootSpan->meta[Tag::SPAN_KIND] = 'server';
            }
            $integration->rootSpan->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;
        };

        \DDTrace\hook_method('App\Application', '__construct', $setRootSpanInfoFn);
        \DDTrace\hook_method('Cake\Http\Server', '__construct', $setRootSpanInfoFn);

        \DDTrace\trace_method(
            'Cake\Controller\Controller',
            'invokeAction',
            function (SpanData $span) use ($integration) {
                $span->name = $span->resource = 'Controller.invokeAction';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->appName;
                $span->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;

                /** @var \Cake\Controller\Controller $this */
                $request = $this->request;
                if (!$request instanceof ServerRequest) {
                    return;
                }

                if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")) {
                    $integration->rootSpan->resource =
                        $_SERVER['REQUEST_METHOD'] . ' ' . $this->name . 'Controller@' . $request->getParam('action');
                }

                if (!array_key_exists(Tag::HTTP_URL, $integration->rootSpan->meta)) {
                    $integration->rootSpan->meta[Tag::HTTP_URL] = Router::url($request->getAttribute('here'), true)
                        . Normalizer::sanitizedQueryString();
                }
                $integration->rootSpan->meta['cakephp.route.controller'] = $request->getParam('controller');
                $integration->rootSpan->meta['cakephp.route.action'] = $request->getParam('action');
                $plugin = $request->getParam('plugin');
                if ($plugin) {
                    $integration->rootSpan->meta['cakephp.plugin'] = $plugin;
                }
            }
        );

        \DDTrace\trace_method('Cake\Error\Middleware\ErrorHandlerMiddleware', 'handleException', [
            'instrument_when_limited' => 1,
            'posthook' => function (SpanData $span, array $args) use ($integration) {
                $integration->setError($integration->rootSpan, $args[0]);
                $span->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;
                return false;
            },
        ]);

        \DDTrace\trace_method('Cake\Http\Response', 'getStatusCode', [
            'instrument_when_limited' => 1,
            'posthook' => function (SpanData $span, $args, $return) use ($integration) {
                $integration->rootSpan->meta[Tag::HTTP_STATUS_CODE] = $return;
                $span->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;
                return false;
            },
        ]);

        // Create a trace span for every template rendered
        \DDTrace\trace_method('Cake\View\View', 'render', function (SpanData $span) use ($integration) {
            $span->name = 'cakephp.view';
            $span->type = Type::WEB_SERVLET;
            /** @var \Cake\View\View $this */
            $file = $this->getTemplatePath() . '/' . $this->getTemplate();
            $span->resource = $file;
            $span->meta = ['cakephp.view' => $file];
            $span->service = $integration->appName;
            $span->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;
        });

        \DDTrace\hook_method(
            'Cake\Routing\Route\Route',
            'parseRequest',
            null,
            function ($app, $appClass, $args, $retval) use ($integration) {
                if (!$retval) {
                    return;
                }

                $integration->rootSpan->meta[Tag::HTTP_ROUTE] = $app->template;
            }
        );
    }
}
