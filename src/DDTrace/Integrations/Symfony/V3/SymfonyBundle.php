<?php

namespace DDTrace\Integrations\Symfony\V3;

use DDTrace\Configuration;
use DDTrace\Encoders\Json;
use DDTrace\Integrations\IntegrationsLoader;
use DDTrace\Span;
use DDTrace\Tags;
use DDTrace\Tracer;
use DDTrace\Transport\Http;
use DDTrace\Types;
use DDTrace\Util\TryCatchFinally;
use DDTrace\GlobalTracer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * DataDog Symfony tracing bundle. Use by installing the dd-trace library:
 *
 * composer require datadog/dd-trace
 *
 * And then add the bundle in app/AppKernel.php:
 *
 *         $bundles = [
 *             // ...
 *             new DDTrace\Integrations\SymfonyBundle(),
 *             // ...
 *         ];
 */
class SymfonyBundle extends Bundle
{
    const NAME = 'symfony';

    public function boot()
    {
        parent::boot();

        if (!Configuration::get()->isIntegrationEnabled(self::NAME)) {
            return;
        }

        if (!extension_loaded('ddtrace')) {
            trigger_error('ddtrace extension required to load Symfony integration.', E_USER_WARNING);
            return;
        }

        if (getenv('APP_ENV') != 'dd_testing' && php_sapi_name() == 'cli') {
            return;
        }

        // Creates a tracer with default transport and default propagators
        $tracer = new Tracer(new Http(new Json()));

        // Sets a global tracer (singleton).
        GlobalTracer::set($tracer);

        // Create a span that starts from when Symfony first boots
        $scope = $tracer->startActiveSpan('symfony.request');
        $appName = $this->getAppName();
        $symfonyRequestSpan = $scope->getSpan();
        $symfonyRequestSpan->setTag(Tags\SERVICE_NAME, $appName);
        $symfonyRequestSpan->setTag(Tags\SPAN_TYPE, Types\WEB_SERVLET);
        $request = null;

        // public function handle(Request $request, $type = HttpKernelInterface::MASTER_REQUEST, $catch = true)
        dd_trace(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handle',
            function () use ($symfonyRequestSpan, &$request) {
                $args = func_get_args();
                /** @var Request $request */
                $request = $args[0];

                $scope = GlobalTracer::get()->startActiveSpan('symfony.kernel.handle');
                $symfonyRequestSpan->setTag(Tags\HTTP_METHOD, $request->getMethod());
                $symfonyRequestSpan->setTag(Tags\HTTP_URL, $request->getUriForPath($request->getPathInfo()));

                $thrown = null;
                $response = null;

                try {
                    $response = call_user_func_array([$this, 'handle'], $args);
                    $symfonyRequestSpan->setTag(Tags\HTTP_STATUS_CODE, $response->getStatusCode());
                } catch (\Exception $e) {
                    $span = $scope->getSpan();
                    $span->setError($e);
                    $thrown = $e;
                }

                $route = $request->get('_route');

                if ($symfonyRequestSpan !== null && $route !== null) {
                    $symfonyRequestSpan->setTag(Tags\RESOURCE_NAME, $route);
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
                $scope = GlobalTracer::get()->startActiveSpan('symfony.kernel.handleException');
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

        // public function dispatch($eventName, Event $event = null)
        dd_trace(
            'Symfony\Component\EventDispatcher\EventDispatcher',
            'dispatch',
            function () use ($symfonyRequestSpan, &$request) {
                $args = func_get_args();
                $scope = GlobalTracer::get()->startActiveSpan('symfony.' . $args[0]);
                SymfonyBundle::injectRouteInfo($args, $request, $symfonyRequestSpan);
                return TryCatchFinally::executePublicMethod($scope, $this, 'dispatch', $args);
            }
        );

        // Enable other integrations
        IntegrationsLoader::load();

        // Tracing templating engines
        $renderTraceCallback = function () use ($appName) {
            $args = func_get_args();

            $scope = GlobalTracer::get()->startActiveSpan('symfony.templating.render');
            $span = $scope->getSpan();
            $span->setTag(Tags\SERVICE_NAME, $appName);
            $span->setTag(Tags\SPAN_TYPE, Types\WEB_SERVLET);
            $span->setTag(Tags\RESOURCE_NAME, get_class($this) . ' ' . $args[0]);
            return TryCatchFinally::executePublicMethod($scope, $this, 'render', $args);
        };

        // This can be replaced once and for all by EngineInterface tracing
        dd_trace('\Symfony\Bridge\Twig\TwigEngine', 'render', $renderTraceCallback);
        dd_trace('\Symfony\Bundle\FrameworkBundle\Templating\TimedPhpEngine', 'render', $renderTraceCallback);
        dd_trace('\Symfony\Bundle\TwigBundle\TwigEngine', 'render', $renderTraceCallback);
        dd_trace('\Symfony\Component\Templating\DelegatingEngine', 'render', $renderTraceCallback);
        dd_trace('\Symfony\Component\Templating\PhpEngine', 'render', $renderTraceCallback);
        dd_trace('Twig_Environment', 'render', $renderTraceCallback);

        // Enable other integrations
        IntegrationsLoader::load();

        // Flushes traces to agent.
        register_shutdown_function(function () use ($scope) {
            $scope->close();
            GlobalTracer::get()->flush();
        });
    }

    /**
     * @param mixed $args
     * @param Request $request
     * @param Span $requestSpan
     */
    public static function injectRouteInfo($args, $request, Span $requestSpan)
    {
        $eventName = $args[0];
        if ($eventName !== KernelEvents::CONTROLLER_ARGUMENTS) {
            return;
        }

        $event = $args[1];
        if (!method_exists($event, 'getController')) {
            return;
        }

        // Controller and action is provided in the form [$controllerInstance, <actionMethodName>]
        $controllerAndAction = $event->getController();

        if (!is_array($controllerAndAction)
            || count($controllerAndAction) !== 2
            || !is_object($controllerAndAction[0])
        ) {
            return;
        }

        $action = get_class($controllerAndAction[0]) . '@' . $controllerAndAction[1];
        $requestSpan->setTag('symfony.route.action', $action);
        $requestSpan->setTag('symfony.route.name', $request->get('_route'));
    }

    private function getAppName()
    {
        if ($appName = getenv('ddtrace_app_name')) {
            return $appName;
        } else {
            return 'symfony';
        }
    }
}
