<?php

namespace DDTrace\Integrations\Symfony\V4;

use DDTrace\Contracts\Span;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Symfony\SymfonyIntegration as DDSymfonyIntegration;
use DDTrace\Integrations\Symfony\SymfonyIntegration;
use DDTrace\Tag;
use DDTrace\Type;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @deprecated: this class is deprecated and should not be added to the list of bundles. Automatic instrumentation
 * from a long time does not require adding any bundle as tracing is done automatically.
 */
class SymfonyBundle extends Bundle
{
    const NAME = 'symfony';

    /**
     * @var string Used by Bundle::getName() to identify this bundle among registered ones.
     */
    protected $name = DDSymfonyIntegration::BUNDLE_NAME;

    public function boot()
    {
        parent::boot();

        if (!Integration::shouldLoad(self::NAME)) {
            return;
        }

        $tracer = GlobalTracer::get();
        $appName = \ddtrace_config_app_name('symfony');

        // Retrieve the web root span for the current host
        $symfonyRequestScope = $tracer->getRootScope();
        $symfonyRequestSpan = $symfonyRequestScope->getSpan();
        $symfonyRequestSpan->setTag(Tag::SERVICE_NAME, $appName);
        $symfonyRequestSpan->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
        $symfonyRequestSpan->overwriteOperationName('symfony.request');
        // Overwriting the default web integration
        $symfonyRequestSpan->setIntegration(SymfonyIntegration::getInstance());
        $symfonyRequestSpan->setTraceAnalyticsCandidate();
        $request = null;

        // public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
        dd_trace(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handle',
            function () use ($symfonyRequestScope, &$request) {
                list($request) = func_get_args();
                $scope = GlobalTracer::get()->startIntegrationScopeAndSpan(
                    SymfonyIntegration::getInstance(),
                    'symfony.kernel.handle'
                );
                $symfonyRequestSpan = $symfonyRequestScope->getSpan();
                $symfonyRequestSpan->setTag(Tag::HTTP_METHOD, $request->getMethod());
                $symfonyRequestSpan->setTag(Tag::HTTP_URL, $request->getUriForPath($request->getPathInfo()));

                $thrown = null;
                $response = null;

                try {
                    $response = dd_trace_forward_call();
                    if ($response) {
                        $symfonyRequestSpan->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode());
                    }
                } catch (\Exception $e) {
                    $span = $scope->getSpan();
                    $span->setError($e);
                    $thrown = $e;
                }

                $route = $request->get('_route');

                if ($symfonyRequestSpan !== null && $route !== null) {
                    $symfonyRequestSpan->setTag(Tag::RESOURCE_NAME, $route);
                }
                $symfonyRequestScope->close();
                $scope->close();

                if ($thrown) {
                    throw $thrown;
                }

                return $response;
            }
        );

        // public function handleException(\Exception $e, Request $request, int $type): Response
        dd_trace(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handleException',
            function (\Exception $e, Request $request, $type) use ($symfonyRequestSpan) {
                $scope = GlobalTracer::get()->startIntegrationScopeAndSpan(
                    SymfonyIntegration::getInstance(),
                    'symfony.kernel.handleException'
                );
                $symfonyRequestSpan->setError($e);

                // PHP 5.4 compliant try-catch-finally block.
                // Note that 'handleException' is a private method.
                $thrown = null;
                $result = null;
                $span = $scope->getSpan();
                try {
                    $result = dd_trace_forward_call();
                } catch (\Exception $ex) {
                    $thrown = $ex;
                    $span->setError($ex);
                }

                $scope->close();
                if ($thrown) {
                    throw $thrown;
                }

                return $result;
            }
        );

        // public function dispatch($eventName, Event $event = null)
        dd_trace(
            'Symfony\Component\EventDispatcher\EventDispatcher',
            'dispatch',
            function () use ($symfonyRequestSpan, &$request) {
                $args = func_get_args();
                if (isset($args[1]) && is_string($args[1])) {
                    $eventName = $args[1];
                } else {
                    $eventName = is_object($args[0]) ? get_class($args[0]) : $args[0];
                }
                $scope = GlobalTracer::get()->startIntegrationScopeAndSpan(
                    SymfonyIntegration::getInstance(),
                    'symfony.' . $eventName
                );
                SymfonyBundle::injectRouteInfo($args, $request, $symfonyRequestSpan);
                return include __DIR__ . '/../../../try_catch_finally.php';
            }
        );

        // Tracing templating engines
        $renderTraceCallback = function () use ($appName) {
            $args = func_get_args();
            $scope = GlobalTracer::get()->startIntegrationScopeAndSpan(
                SymfonyIntegration::getInstance(),
                'symfony.templating.render'
            );
            $span = $scope->getSpan();
            $span->setTag(Tag::SERVICE_NAME, $appName);
            $span->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
            $span->setTag(Tag::RESOURCE_NAME, get_class($this) . ' ' . $args[0]);
            return include __DIR__ . '/../../../try_catch_finally.php';
        };

        // Tracing templating engine
        dd_trace('Twig_Environment', 'render', $renderTraceCallback);
        dd_trace('Twig\Environment', 'render', $renderTraceCallback);
    }

    /**
     * @param mixed $args
     * @param Request $request
     * @param Span $requestSpan
     */
    public static function injectRouteInfo($args, $request, Span $requestSpan)
    {
        if (count($args) < 2) {
            return;
        }
        if (is_object($args[0])) {
            list($event, $eventName) = $args;
        } else {
            list($eventName, $event) = $args;
        }
        if ($eventName !== KernelEvents::CONTROLLER_ARGUMENTS) {
            return;
        }

        if (!method_exists($event, 'getController')) {
            return;
        }

        // Controller and action is provided in the form [$controllerInstance, <actionMethodName>]
        $controllerAndAction = $event->getController();

        if (
            !is_array($controllerAndAction)
            || count($controllerAndAction) !== 2
            || !is_object($controllerAndAction[0])
        ) {
            return;
        }

        $action = get_class($controllerAndAction[0]) . '@' . $controllerAndAction[1];
        $requestSpan->setTag('symfony.route.action', $action);
        $requestSpan->setTag('symfony.route.name', $request->get('_route'));
    }
}
