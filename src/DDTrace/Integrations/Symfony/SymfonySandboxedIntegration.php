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
        $appName = null;
        $symfonyRequestSpan = null;
        $isSymfony2 = false;

        dd_trace_method(
            'Symfony\Component\HttpKernel\HttpKernel',
            '__construct',
            function () use (&$appName, &$symfonyRequestSpan, $integration, &$isSymfony2) {
                $tracer = GlobalTracer::get();
                $scope = $tracer->getRootScope();
                /** @var Span $symfonyRequestSpan */
                $symfonyRequestSpan = $scope->getSpan();

                if (
                    defined('\Symfony\Component\HttpKernel\Kernel::VERSION')
                        && Versions::versionMatches('2', \Symfony\Component\HttpKernel\Kernel::VERSION)
                ) {
                    $isSymfony2 = true;
                    return false;
                }

                $appName = Configuration::get()->appName('symfony');
                $symfonyRequestSpan->overwriteOperationName('symfony.request');
                $symfonyRequestSpan->setTag(Tag::SERVICE_NAME, $appName);
                $symfonyRequestSpan->setIntegration($integration);
                $symfonyRequestSpan->setTraceAnalyticsCandidate();

                return false;
            }
        );

        dd_trace_method(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handle',
            function (
                SpanData $span,
                $args,
                $response
            ) use (
                &$appName,
                &$symfonyRequestSpan,
                $integration,
                &$isSymfony2
            ) {
                if ($isSymfony2) {
                    // Disabled for symfony 2
                    return false;
                }

                /** @var Request $request */
                list($request) = $args;

                $span->name = $span->resource = 'symfony.kernel.handle';
                $span->service = $appName;
                $span->type = Type::WEB_SERVLET;

                $symfonyRequestSpan->setTag(Tag::HTTP_METHOD, $request->getMethod());
                $symfonyRequestSpan->setTag(Tag::HTTP_URL, $request->getUriForPath($request->getPathInfo()));
                $symfonyRequestSpan->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode());

                $route = $request->get('_route');
                if (null !== $route && null !== $request) {
                    $symfonyRequestSpan->setTag(Tag::RESOURCE_NAME, $route);
                    $symfonyRequestSpan->setTag('symfony.route.name', $route);
                }
            }
        );

        dd_trace_method(
            'Symfony\Component\EventDispatcher\EventDispatcher',
            'dispatch',
            function (SpanData $span, $args) use (&$appName, &$symfonyRequestSpan, $integration, &$isSymfony2) {
                if ($isSymfony2) {
                    // Disabled for symfony 2
                    return false;
                }

                if (isset($args[1]) && is_string($args[1])) {
                    $eventName = $args[1];
                } else {
                    $eventName = is_object($args[0]) ? get_class($args[0]) : $args[0];
                }
                $span->name = $span->resource = 'symfony.' . $eventName;
                $span->service = $appName;
                $integration->injectActionInfo($args, $symfonyRequestSpan);
            }
        );

        dd_trace_method(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handleException',
            function (SpanData $span, $args) use (&$appName, &$symfonyRequestSpan, $integration, &$isSymfony2) {
                if ($isSymfony2) {
                    // Disabled for symfony 2
                    return false;
                }

                $span->name = $span->resource = 'symfony.kernel.handleException';
                $span->service = $appName;
                $symfonyRequestSpan->setError($args[0]);
            }
        );

        // Tracing templating engines
        $renderTraceCallback = function (SpanData $span, $args) use (&$appName, $integration, &$isSymfony2) {
            if ($isSymfony2) {
                // Disabled for symfony 2
                return false;
            }

            $span->name = 'symfony.templating.render';
            $span->service = $appName;
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

        // Symfony 2.x specific resource name assignment
        dd_trace_method(
            'Symfony\Component\HttpKernel\Event\FilterControllerEvent',
            'setController',
            function (SpanData $span, $args) use (&$symfonyRequestSpan, $integration, &$isSymfony2) {
                if (!$isSymfony2) {
                    return false;
                }

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

                if ($symfonyRequestSpan) {
                    $symfonyRequestSpan->setIntegration($integration);
                    if (count($resourceParts) > 0) {
                        $symfonyRequestSpan->setResource(implode(' ', $resourceParts));
                    }
                }

                return false;
            }
        );

        return Integration::LOADED;
    }

    /**
     * @param mixed $args
     * @param Request $request
     * @param Span $requestSpan
     */
    public function injectActionInfo($args, Span $requestSpan)
    {
        if (count($args) < 2) {
            return;
        }
        if (is_object($args[0])) {
            list($event, $eventName) = $args;
        } else {
            list($eventName, $event) = $args;
        }

        if (
            defined("KernelEvents::CONTROLLER_ARGUMENTS")
                &&  $eventName !== KernelEvents::CONTROLLER_ARGUMENTS
        ) {
            // Symfony 3.0 check
            return;
        } elseif ($eventName !== KernelEvents::CONTROLLER) {
            // Symfony 3.3+ check (we do not test 3.1 and 3.2 so we do not know
            // under which case they fall)
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
    }
}
