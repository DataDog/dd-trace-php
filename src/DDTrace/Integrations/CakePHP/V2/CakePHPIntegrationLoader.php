<?php

namespace DDTrace\Integrations\CakePHP\V2;

use CakeEvent;
use CakeRequest;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\CakePHP\CakePHPIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\Contracts\Span;
use DDTrace\Tag;
use DDTrace\Type;
use Router;

class CakePHPIntegrationLoader
{
    /**
     * @var Span
     */
    public $rootSpan;

    public function load(CakePHPIntegration $integration)
    {
        // Very strange workaround to get the integration to load in tests
        // We need to find this bug in the CLI SAPI's built-in web server
        // Once the bug is fixed we can remove this line block
        if ('true' === getenv('DD_TEST_INTEGRATION')) {
            echo ' ';
        }
        $this->rootSpan = GlobalTracer::get()->getRootScope()->getSpan();
        // Overwrite the default web integration
        $this->rootSpan->setIntegration($integration);
        $this->rootSpan->setTraceAnalyticsCandidate();
        $this->rootSpan->overwriteOperationName('cakephp.request');
        $this->rootSpan->setTag(Tag::SERVICE_NAME, \ddtrace_config_app_name(CakePHPIntegration::NAME));

        $loader = $this;

        dd_trace('Controller', 'invokeAction', function (CakeRequest $request) use ($loader) {
            $loader->rootSpan->setTag(
                Tag::RESOURCE_NAME,
                $_SERVER['REQUEST_METHOD'] . ' ' . $this->name . 'Controller@' . $request->params['action']
            );
            $loader->rootSpan->setTag(Tag::HTTP_URL, Router::url($request->here, true));
            $loader->rootSpan->setTag('cakephp.route.controller', $request->params['controller']);
            $loader->rootSpan->setTag('cakephp.route.action', $request->params['action']);
            if (isset($request->params['plugin'])) {
                $loader->rootSpan->setTag('cakephp.plugin', $request->params['plugin']);
            }
            return dd_trace_forward_call();
        });

        // This only traces the default exception renderer
        // We can remove this when we auto-trace exceptions and errors
        // Other possible places to trace
        // - ErrorHandler::handleException()
        // - Controller::appError()
        // - Exception.handler
        // - Exception.renderer
        dd_trace('ExceptionRenderer', '__construct', function ($exception) use ($loader) {
            $loader->rootSpan->setError($exception);
            return dd_trace_forward_call();
        });

        // Create a trace span for every template rendered
        dd_trace('View', 'render', function () use ($integration) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }
            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'cakephp.view');
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
            $file = $this->viewPath . '/' . $this->view . $this->ext;
            $scope->getSpan()->setTag(Tag::RESOURCE_NAME, $file);
            $scope->getSpan()->setTag('cakephp.view', $file);
            return include __DIR__ . '/../../../try_catch_finally.php';
        });

        /**
         * CakePHP Console
         */
        //dd_trace('ShellDispatcher', '__construct', function () use ($self) {
        // Temporary workaround until we fix request_init_hook for non-autoloaded projects
        dd_trace('ShellDispatcher', 'dispatch', function () use ($loader) {
            $loader->rootSpan->overwriteOperationName('cakephp.console');
            $loader->rootSpan->setTag(
                Tag::RESOURCE_NAME,
                !empty($_SERVER['argv'][1]) ? 'cake_console ' . $_SERVER['argv'][1] : 'cake_console'
            );
            return dd_trace_forward_call();
        });

        // This is called from the exception handler and doesn't get traced
        // We can remove this when we auto-trace exceptions and errors
        dd_trace('ConsoleErrorHandler', 'handleException', function ($exception) {
            $span = GlobalTracer::get()->getActiveSpan();
            $span->setError($exception);
            return dd_trace_forward_call();
        });

        return Integration::LOADED;
    }
}
