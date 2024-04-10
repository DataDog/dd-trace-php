<?php

namespace DDTrace\Integrations\CakePHP\V3;

use Cake\Http\ServerRequest;
use Cake\Routing\Router;
use DDTrace\HookData;
use DDTrace\Integrations\CakePHP\CakePHPIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use function DDTrace\hook_method;

class CakePHPIntegrationLoader
{
    public function load($integration)
    {
        $setRootSpanInfoFn = function () use ($integration) {
            $rootSpan = \DDTrace\root_span();
            if ($rootSpan === null) {
                return;
            }

            $integration->appName = \ddtrace_config_app_name(CakePHPIntegration::NAME);
            $integration->addTraceAnalyticsIfEnabled($rootSpan);
            $rootSpan->service = $integration->appName;
            if ('cli' === PHP_SAPI) {
                $rootSpan->name = 'cakephp.console';
                $rootSpan->resource = !empty($_SERVER['argv'][1])
                    ? 'cake_console ' . $_SERVER['argv'][1]
                    : 'cake_console';
            } else {
                $rootSpan->name = 'cakephp.request';
                $rootSpan->meta[Tag::SPAN_KIND] = 'server';
            }
            $rootSpan->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;
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

                $rootSpan = \DDTrace\root_span();

                if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")) {
                    $rootSpan->resource =
                        $_SERVER['REQUEST_METHOD'] . ' ' . $this->name . 'Controller@' . $request->getParam('action');
                }

                if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                    $rootSpan->meta[Tag::HTTP_URL] = Router::url($request->getAttribute('here'), true)
                        . Normalizer::sanitizedQueryString();
                }
                $rootSpan->meta['cakephp.route.controller'] = $request->getParam('controller');
                $rootSpan->meta['cakephp.route.action'] = $request->getParam('action');
                $plugin = $request->getParam('plugin');
                if ($plugin) {
                    $rootSpan->meta['cakephp.plugin'] = $plugin;
                }
            }
        );

        \DDTrace\hook_method(
            'Cake\Error\Middleware\ErrorHandlerMiddleware',
            'handleException',
            function ($This, $scope, $args) use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan !== null) {
                    $integration->setError($rootSpan, $args[0]);
                }
        });

        \DDTrace\hook_method(
            'Cake\Http\Response',
            'getStatusCode',
            null,
            function ($This, $scope, $args, $retval) use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan) {
                    $rootSpan->meta[Tag::HTTP_STATUS_CODE] = $retval;
                }
            }
        );

        // Create a trace span for every template rendered
        \DDTrace\install_hook(
            'Cake\View\View::render',
            function (HookData $renderHook) {
                $renderHook->span();

                // The next Cake\View\View::_getViewFileName (v3) or Cake\View\View::_getTemplateFileName (v4+) call
                // will happen from render and will return the filename of the given action template file with the
                // extension (e.g., .ctp, .twig)
                $methodName = version_compare(\Cake\Core\Configure::version(), '4.0.0', '>=') ?
                    'Cake\View\View::_getTemplateFileName' : 'Cake\View\View::_getViewFileName';
                \DDTrace\install_hook(
                    $methodName,
                    null,
                    function (HookData $hook) use ($renderHook) {
                        $renderHook->data['viewFileName'] = $hook->returned;
                        \DDTrace\remove_hook($hook->id);
                    }
                );
            }, function (HookData $renderHook) use ($integration) {
                $span = $renderHook->span();
                $span->name = 'cakephp.view';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->appName;
                $span->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;

                $absoluteFilePath = $renderHook->data['viewFileName'] ?? '';
                $fileExtension = pathinfo($absoluteFilePath, PATHINFO_EXTENSION);
                $fileExtension = $fileExtension ? '.' . $fileExtension : '';
                /** @var \Cake\View\View $this */
                $file = $this->getTemplatePath() . '/' . $this->getTemplate() . $fileExtension;
                $span->resource = $file;
                $span->meta['cakephp.view'] = $file;

                $plugin = $this->getPlugin();
                if ($plugin) {
                    $span->meta['cakephp.plugin'] = $plugin;
                }

                $theme = $this->getTheme();
                if ($theme) {
                    $span->meta['cakephp.theme'] = $theme;
                }
        });

        \DDTrace\hook_method(
            'Cake\Routing\Route\Route',
            'parseRequest',
            null,
            function ($app, $appClass, $args, $retval) use ($integration) {
                if (!$retval) {
                    return;
                }

                $rootSpan = \DDTrace\root_span();
                if ($rootSpan) {
                    $rootSpan->meta[Tag::HTTP_ROUTE] = $app->template;
                }
            }
        );

        return Integration::LOADED;
    }
}
