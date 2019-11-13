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
        $tracer = GlobalTracer::get();

        // Create a span that starts from when Symfony first boots
        $scope = $tracer->getRootScope();
        $appName = Configuration::get()->appName('symfony');
        /** @var Span $symfonyRequestSpan */
        $symfonyRequestSpan = $scope->getSpan();
        $symfonyRequestSpan->overwriteOperationName('symfony.request');
        $symfonyRequestSpan->setTag(Tag::SERVICE_NAME, $appName);
        $symfonyRequestSpan->setIntegration($integration);
        $symfonyRequestSpan->setTraceAnalyticsCandidate();

        dd_trace_method(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handle',
            function (SpanData $span, $args, $response) use ($appName, $symfonyRequestSpan, $integration) {
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
            function (SpanData $span, $args) use ($appName, $integration, $symfonyRequestSpan) {
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
            function (SpanData $span, $args) use ($appName, $symfonyRequestSpan, $integration) {
                $span->name = $span->resource = 'symfony.kernel.handleException';
                $span->service = $appName;
                $symfonyRequestSpan->setError($args[0]);
            }
        );

        // Tracing templating engines
        $renderTraceCallback = function (SpanData $span, $args) use ($appName, $integration) {
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
    }
}
