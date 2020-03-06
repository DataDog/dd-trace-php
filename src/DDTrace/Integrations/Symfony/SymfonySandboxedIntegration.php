<?php

namespace DDTrace\Integrations\Symfony;

use DDTrace\Configuration;
use DDTrace\Contracts\Span;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Versions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;

class SymfonySandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'symfony';

    public $symfonyRequestSpan;
    public $appName;

    public function getName()
    {
        return static::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return false;
    }

    /**
     * Load the integration
     *
     * @return int
     */
    public function init()
    {
        $integration = $this;

        dd_trace_method(
            'Symfony\Component\HttpKernel\HttpKernel',
            '__construct',
            function () use ($integration) {
                $tracer = GlobalTracer::get();
                $scope = $tracer->getRootScope();
                if (!$scope) {
                    return false;
                }
                /** @var Span $symfonyRequestSpan */
                $integration->symfonyRequestSpan = $scope->getSpan();

                if (
                    defined('\Symfony\Component\HttpKernel\Kernel::VERSION')
                        && Versions::versionMatches('2', \Symfony\Component\HttpKernel\Kernel::VERSION)
                ) {
                    $integration->loadSymfony2($integration);
                    return false;
                }

                $integration->loadSymfony($integration);
                return false;
            }
        );

        return Integration::LOADED;
    }

    public function loadSymfony($integration)
    {
        $integration->appName = Configuration::get()->appName('symfony');
        $integration->symfonyRequestSpan->overwriteOperationName('symfony.request');
        $integration->symfonyRequestSpan->setTag(Tag::SERVICE_NAME, $integration->appName);
        $integration->symfonyRequestSpan->setIntegration($integration);
        $integration->symfonyRequestSpan->setTraceAnalyticsCandidate();

        dd_trace_method(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handle',
            function (SpanData $span, $args, $response) use ($integration) {
                /** @var Request $request */
                list($request) = $args;

                $span->name = $span->resource = 'symfony.kernel.handle';
                $span->service = $integration->appName;
                $span->type = Type::WEB_SERVLET;

                $integration->symfonyRequestSpan->setTag(Tag::HTTP_METHOD, $request->getMethod());
                $integration->symfonyRequestSpan->setTag(
                    Tag::HTTP_URL,
                    $request->getUriForPath($request->getPathInfo())
                );
                if (isset($response)) {
                    $integration->symfonyRequestSpan->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode());
                }

                $route = $request->get('_route');
                if (null !== $route && null !== $request) {
                    $integration->symfonyRequestSpan->setTag(Tag::RESOURCE_NAME, $route);
                    $integration->symfonyRequestSpan->setTag('symfony.route.name', $route);
                }
            }
        );

        /*
         * EventDispatcher v4.3 introduced an arg hack that mutates the arguments.
         * @see https://github.com/symfony/event-dispatcher/blob/4.3/EventDispatcher.php#L51-L64
         * Since the arguments passed to the tracing closure on PHP 7 are mutable,
         * the closure must be run _before_ the original call via 'prehook'.
        */
        $hookType = (PHP_MAJOR_VERSION >= 7) ? 'prehook' : 'posthook';

        dd_trace_method(
            'Symfony\Component\EventDispatcher\EventDispatcher',
            'dispatch',
            [
                $hookType => function (SpanData $span, $args) use ($integration) {
                    if (!isset($args[0])) {
                        return false;
                    }
                    if (\is_object($args[0])) {
                        // dispatch($event, string $eventName = null)
                        $event = $args[0];
                        $eventName = isset($args[1]) && \is_string($args[1]) ? $args[1] : \get_class($event);
                    } elseif (\is_string($args[0])) {
                        // dispatch($eventName, Event $event = null)
                        $eventName = $args[0];
                        $event = isset($args[1]) && \is_object($args[1]) ? $args[1] : null;
                    } else {
                        // Invalid API usage
                        return false;
                    }
                    $span->name = $span->resource = 'symfony.' . $eventName;
                    $span->service = $integration->appName;
                    if ($event === null) {
                        return;
                    }
                    $integration->injectActionInfo($event, $eventName, $integration->symfonyRequestSpan);
                }
            ]
        );

        dd_trace_method(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handleException',
            function (SpanData $span, $args) use ($integration) {
                $span->name = $span->resource = 'symfony.kernel.handleException';
                $span->service = $integration->appName;
                $integration->symfonyRequestSpan->setError($args[0]);
            }
        );

        // Tracing templating engines
        $renderTraceCallback = function (SpanData $span, $args) use ($integration) {
            $span->name = 'symfony.templating.render';
            $span->service = $integration->appName;
            $span->type = Type::WEB_SERVLET;

            $resourceName = count($args) > 0 ? get_class($this) . ' ' . $args[0] : get_class($this);
            $span->resource = $resourceName;
            $span->meta[Tag::INTEGRATION_NAME] = $integration->getName();
        };
        dd_trace_method('Symfony\Bridge\Twig\TwigEngine', 'render', $renderTraceCallback);
        dd_trace_method('Symfony\Bundle\FrameworkBundle\Templating\TimedPhpEngine', 'render', $renderTraceCallback);
        dd_trace_method('Symfony\Bundle\TwigBundle\TwigEngine', 'render', $renderTraceCallback);
        dd_trace_method('Symfony\Component\Templating\DelegatingEngine', 'render', $renderTraceCallback);
        dd_trace_method('Symfony\Component\Templating\PhpEngine', 'render', $renderTraceCallback);
        dd_trace_method('Twig\Environment', 'render', $renderTraceCallback);
        dd_trace_method('Twig_Environment', 'render', $renderTraceCallback);
    }

    public function loadSymfony2($integration)
    {
        // Symfony 2.x specific resource name assignment
        dd_trace_method(
            'Symfony\Component\HttpKernel\Event\FilterControllerEvent',
            'setController',
            function (SpanData $span, $args) use ($integration) {
                list($controllerInfo) = $args;
                $resourceParts = [];

                // Controller info can be provided in various ways.
                if (is_string($controllerInfo)) {
                    $resourceParts[] = $controllerInfo;
                } elseif (is_array($controllerInfo) && count($controllerInfo) === 2) {
                    if (is_object($controllerInfo[0])) {
                        $resourceParts[] = get_class($controllerInfo[0]);
                    } elseif (is_string($controllerInfo[0])) {
                        $resourceParts[] = $controllerInfo[0];
                    }

                    if (is_string($controllerInfo[1])) {
                        $resourceParts[] = $controllerInfo[1];
                    }
                }

                if ($integration->symfonyRequestSpan) {
                    $integration->symfonyRequestSpan->setIntegration($integration);
                    if (count($resourceParts) > 0) {
                        $integration->symfonyRequestSpan->setResource(\implode(' ', $resourceParts));
                    }
                }

                return false;
            }
        );
    }

    /**
     * @param \Symfony\Component\EventDispatcher\Event $event
     * @param string $eventName
     * @param Span $requestSpan
     */
    public function injectActionInfo($event, $eventName, Span $requestSpan)
    {
        if (
            !\defined("\Symfony\Component\HttpKernel\KernelEvents::CONTROLLER")
            || $eventName !== KernelEvents::CONTROLLER
            || !method_exists($event, 'getController')
        ) {
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
    }
}
