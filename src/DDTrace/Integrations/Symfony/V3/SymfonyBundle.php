<?php

namespace DDTrace\Integrations\Symfony\V3;

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

        // Create a span that starts from when Symfony first boots
        $scope = $tracer->getRootScope();
        $appName = \ddtrace_config_app_name('symfony');
        $symfonyRequestSpan = $scope->getSpan();
        $symfonyRequestSpan->overwriteOperationName('symfony.request');
        // Overwriting the default web integration
        $symfonyRequestSpan->setIntegration(SymfonyIntegration::getInstance());
        $symfonyRequestSpan->setTraceAnalyticsCandidate();
        $symfonyRequestSpan->setTag(Tag::SERVICE_NAME, $appName);
        $request = null;

        // public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
        dd_trace(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handle',
            function () use ($symfonyRequestSpan, &$request) {
                /** @var Request $request */
                list($request) = func_get_args();

                $scope = GlobalTracer::get()->startIntegrationScopeAndSpan(
                    SymfonyIntegration::getInstance(),
                    'symfony.kernel.handle'
                );
                $symfonyRequestSpan->setTag(Tag::HTTP_METHOD, $request->getMethod());
                $symfonyRequestSpan->setTag(Tag::HTTP_URL, $request->getUriForPath($request->getPathInfo()));

                $thrown = null;
                $response = null;

                try {
                    $response = dd_trace_forward_call();
                    $symfonyRequestSpan->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode());
                } catch (\Exception $e) {
                    $span = $scope->getSpan();
                    $span->setError($e);
                    $thrown = $e;
                }

                $route = $request->get('_route');

                if ($symfonyRequestSpan !== null && $route !== null) {
                    $symfonyRequestSpan->setTag(Tag::RESOURCE_NAME, $route);
                }
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
                    $result = $this->handleException($e, $request, $type);
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

        $tracedEventDispatcherClasses = [];
        dd_trace(
            'Symfony\Component\HttpKernel\HttpKernel',
            '__construct',
            function ($eventName, $event = null) use (&$tracedEventDispatcherClasses, &$request, &$symfonyRequestSpan) {
                $args = func_get_args();
                if (count($args) > 0) {
                    $dispatcherClass = get_class($args[0]);
                    if (!in_array($dispatcherClass, $tracedEventDispatcherClasses)) {
                        $tracedEventDispatcherClasses[] = $dispatcherClass;

                        dd_trace($dispatcherClass, 'dispatch', function () use (&$request, &$symfonyRequestSpan) {
                            $args = func_get_args();
                            $scope = GlobalTracer::get()->startIntegrationScopeAndSpan(
                                SymfonyIntegration::getInstance(),
                                'symfony.' . $args[0]
                            );
                            SymfonyBundle::injectRouteInfo($args, $request, $symfonyRequestSpan);
                            return include __DIR__ . '/../../../try_catch_finally.php';
                        });
                    }
                }

                return dd_trace_forward_call();
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

        // This can be replaced once and for all by EngineInterface tracing
        dd_trace('Symfony\Bridge\Twig\TwigEngine', 'render', $renderTraceCallback);
        dd_trace('Symfony\Bundle\FrameworkBundle\Templating\TimedPhpEngine', 'render', $renderTraceCallback);
        dd_trace('Symfony\Bundle\TwigBundle\TwigEngine', 'render', $renderTraceCallback);
        dd_trace('Symfony\Component\Templating\DelegatingEngine', 'render', $renderTraceCallback);
        dd_trace('Symfony\Component\Templating\PhpEngine', 'render', $renderTraceCallback);
        dd_trace('Twig\Environment', 'render', $renderTraceCallback);
        dd_trace('Twig_Environment', 'render', $renderTraceCallback);
    }

    /**
     * @param mixed $args
     * @param Request $request
     * @param Span $requestSpan
     */
    public static function injectRouteInfo($args, $request, Span $requestSpan)
    {
        $eventName = $args[0];
        if (defined("KernelEvents::CONTROLLER_ARGUMENTS")) {
            if ($eventName !== KernelEvents::CONTROLLER_ARGUMENTS) {
                return;
            }
        } elseif ($eventName !== KernelEvents::CONTROLLER) {
            return;
        }

        $event = $args[1];
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

        if ($route = $request->get('_route')) {
            $rootSpan = GlobalTracer::get()->getRootScope()->getSpan();
            $rootSpan->setResource($route);
        }
    }
}
