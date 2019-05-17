<?php

namespace DDTrace\Integrations\Slim\V3;

use DDTrace\GlobalTracer;
use DDTrace\Integrations\Integration;
use DDTrace\Contracts\Span;
use DDTrace\Integrations\Slim\SlimIntegration;
use DDTrace\Tag;
use DDTrace\Type;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class SlimIntegrationLoader
{
    /**
     * @var Span
     */
    public $rootSpan;

    public function load(SlimIntegration $integration)
    {
        $this->rootSpan = GlobalTracer::get()->getRootScope()->getSpan();
        // Overwrite the default web integration
        $this->rootSpan->setIntegration($integration);
        $this->rootSpan->setTraceAnalyticsCandidate();
        $this->rootSpan->overwriteOperationName('slim.request');
        $this->rootSpan->setTag(Tag::SERVICE_NAME, SlimIntegration::getAppName());

        $loader = $this;

        // Trace routes
        // If the tracer ever supports tracing an interface, we should trace the following:
        // Slim\Interfaces\RouterInterface::lookupRoute
        dd_trace('Slim\Router', 'lookupRoute', function () use ($loader) {
            /** @var \Slim\Interfaces\RouteInterface $route */
            $route = dd_trace_forward_call();
            $loader->rootSpan->setTag(
                Tag::RESOURCE_NAME,
                $_SERVER['REQUEST_METHOD'] . ' ' . ($route->getName() ?: $route->getPattern())
            );
            return $route;
        });

        // Trace controllers
        $traceControllers = function (
            callable $callable,
            ServerRequestInterface $request,
            ResponseInterface $response,
            array $routeArguments
        ) use ($loader) {
            $loader->rootSpan->setTag(Tag::HTTP_URL, (string) $request->getUri());
            $callableName = '{unknown callable}';
            is_callable($callable, false, $callableName);
            $loader->rootSpan->setTag('slim.route.controller', $callableName);
            return dd_trace_forward_call();
        };
        // If the tracer ever supports tracing an interface, we should trace the following:
        // Slim\Interfaces\InvocationStrategyInterface::__invoke
        dd_trace('Slim\Handlers\Strategies\RequestResponse', '__invoke', $traceControllers);
        dd_trace('Slim\Handlers\Strategies\RequestResponseArgs', '__invoke', $traceControllers);

        // Trace exceptions
        // Added in v3.1.0 so exceptions aren't traced in v3.0.x
        dd_trace('Slim\App', 'handleException', function ($exception, $request, $response) {
            GlobalTracer::get()->getActiveSpan()->setError($exception);
            return dd_trace_forward_call();
        });

        // Trace view renderings
        // Requires slim/twig-view 2.x
        dd_trace('Slim\Views\Twig', 'render', function (
            ResponseInterface $response,
            $template,
            $data = []
        ) use ($integration) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }
            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'slim.view');
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
            $scope->getSpan()->setTag(Tag::RESOURCE_NAME, $template);
            $scope->getSpan()->setTag('slim.view', $template);
            return include __DIR__ . '/../../../try_catch_finally.php';
        });

        return Integration::LOADED;
    }
}
