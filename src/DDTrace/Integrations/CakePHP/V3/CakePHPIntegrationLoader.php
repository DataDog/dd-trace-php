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

class CakePHPIntegrationLoader
{
    public static function load()
    {
        \DDTrace\hook_method('App\Application', '__construct', CakePHPIntegration::$setRootSpanInfoFn);
        \DDTrace\hook_method('Cake\Http\Server', '__construct', CakePHPIntegration::$setRootSpanInfoFn);

        \DDTrace\trace_method(
            'Cake\Controller\Controller',
            'invokeAction',
            function (SpanData $span) {
                $span->name = $span->resource = 'Controller.invokeAction';
                $span->type = Type::WEB_SERVLET;
                $span->service = CakePHPIntegration::$appName;
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
            CakePHPIntegration::$handleExceptionFn
        );

        \DDTrace\hook_method(
            'Cake\Http\Response',
            'getStatusCode',
            null,
            CakePHPIntegration::$setStatusCodeFn
        );

        // Create a trace span for every template rendered
        \DDTrace\install_hook(
            'Cake\View\View::render',
            static function (HookData $renderHook) {
                $renderHook->span();

                // The next Cake\View\View::_getViewFileName (v3) or Cake\View\View::_getTemplateFileName (v4+) call
                // will happen from render and will return the filename of the given action template file with the
                // extension (e.g., .ctp, .twig)
                $methodName = version_compare(\Cake\Core\Configure::version(), '4.0.0', '>=') ?
                    'Cake\View\View::_getTemplateFileName' : 'Cake\View\View::_getViewFileName';
                \DDTrace\install_hook(
                    $methodName,
                    null,
                    static function (HookData $hook) use ($renderHook) {
                        $renderHook->data['viewFileName'] = $hook->returned;
                        \DDTrace\remove_hook($hook->id);
                    }
                );
            }, function (HookData $renderHook) {
                $span = $renderHook->span();
                $span->name = 'cakephp.view';
                $span->type = Type::WEB_SERVLET;
                $span->service = CakePHPIntegration::$appName;
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
            CakePHPIntegration::$parseRouteFn
        );

        return Integration::LOADED;
    }
}
