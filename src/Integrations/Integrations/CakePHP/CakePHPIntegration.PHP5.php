<?php

namespace DDTrace\Integrations\CakePHP;

use CakeRequest;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use Router;

class CakePHPIntegration extends Integration
{
    const NAME = 'cakephp';

    public $appName;
    public $rootSpan;

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return self::NOT_AVAILABLE;
        }

        $integration = $this;

        // CakePHP v2.x - we don't need to check for v3 since it does not have \Dispatcher or \ShellDispatcher
        $initCakeV2 = function () use ($integration) {
            // Since "Dispatcher" and "App" are common names, check for a CakePHP signature before loading
            if (!defined('CAKE_CORE_INCLUDE_PATH')) {
                return false;
            }

            $rootSpan = \DDTrace\root_span();
            if (!$rootSpan) {
                return false;
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

            $integration->rootSpan->meta[Tag::SPAN_KIND] = 'server';
            $integration->rootSpan->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;

            \DDTrace\trace_method(
                'Controller',
                'invokeAction',
                function (SpanData $span, array $args) use ($integration) {
                    $span->name = $span->resource = 'Controller.invokeAction';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $integration->appName;
                    $span->meta[Tag::SPAN_KIND] = 'server';
                    $span->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;

                    $request = $args[0];
                    if (!$request instanceof CakeRequest) {
                        return;
                    }

                    $integration->rootSpan->resource =
                        $_SERVER['REQUEST_METHOD'] . ' ' . $this->name . 'Controller@' . $request->params['action'];
                    $integration->rootSpan->meta[Tag::HTTP_URL] = Router::url($request->here, true)
                        . Normalizer::sanitizedQueryString();
                    $integration->rootSpan->meta['cakephp.route.controller'] = $request->params['controller'];
                    $integration->rootSpan->meta['cakephp.route.action'] = $request->params['action'];
                    if (isset($request->params['plugin'])) {
                        $integration->rootSpan->meta['cakephp.plugin'] = $request->params['plugin'];
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
            \DDTrace\trace_method('ExceptionRenderer', '__construct', [
                'instrument_when_limited' => 1,
                'posthook' => function (SpanData $span, array $args) use ($integration) {
                    $integration->setError($integration->rootSpan, $args[0]);
                    $span->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;
                    return false;
                },
            ]);

            \DDTrace\trace_method('CakeResponse', 'statusCode', [
                'instrument_when_limited' => 1,
                'posthook' => function (SpanData $span, $args, $return) use ($integration) {
                    $integration->rootSpan->meta[Tag::HTTP_STATUS_CODE] = $return;
                    $span->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;
                    return false;
                },
            ]);

            // Create a trace span for every template rendered
            \DDTrace\trace_method('View', 'render', function (SpanData $span) use ($integration) {
                $span->name = 'cakephp.view';
                $span->type = Type::WEB_SERVLET;
                $file = $this->viewPath . '/' . $this->view . $this->ext;
                $span->resource = $file;
                $span->meta = ['cakephp.view' => $file];
                $span->service = $integration->appName;
                $span->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;
            });

            return false;
        };

        if ('cli' === PHP_SAPI) {
            // CLI bootstrap
            //\DDTrace\trace_method('ShellDispatcher', '__construct', $initCakeV2);
            // Workaround until we fix request_init_hook for non-autoloaded projects
            \DDTrace\trace_method('App', 'init', $initCakeV2);
        } else {
            // Web bootstrap
            \DDTrace\trace_method('Dispatcher', '__construct', $initCakeV2);
        }

        return Integration::LOADED;
    }
}
