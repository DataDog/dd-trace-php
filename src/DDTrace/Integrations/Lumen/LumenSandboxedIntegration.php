<?php

namespace DDTrace\Integrations\Lumen;

use DDTrace\GlobalTracer;
use DDTrace\SpanData;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\Tag;

/**
 * Lumen Sandboxed integration
 */
class LumenSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'lumen';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling()
    {
        return false;
    }

    /**
     * @return int
     */
    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return SandboxedIntegration::NOT_LOADED;
        }

        $rootScope = GlobalTracer::get()->getRootScope();
        $rootSpan = null;

        if (null === $rootScope || null === ($rootSpan = $rootScope->getSpan())) {
            return SandboxedIntegration::NOT_LOADED;
        }

        $integration = $this;
        $appName = \ddtrace_config_app_name(self::NAME);

        \dd_trace_method(
            'Laravel\Lumen\Application',
            'prepareRequest',
            function (SpanData $span, $args) use ($rootSpan, $integration, $appName) {
                $request = $args[0];
                $rootSpan->overwriteOperationName('lumen.request');
                $rootSpan->setTag(Tag::SERVICE_NAME, $appName);
                $integration->addTraceAnalyticsIfEnabledLegacy($rootSpan);
                $rootSpan->setTag(Tag::HTTP_URL, $request->getUri());
                $rootSpan->setTag(Tag::HTTP_METHOD, $request->getMethod());
                return false;
            }
        );

        // Extracting resource name as in legacy integration
        \dd_trace_method(
            'Laravel\Lumen\Application',
            'handleFoundRoute',
            [
                'prehook' => function (SpanData $span, $args) use ($rootSpan) {
                    if (count($args) < 1 || !\is_array($args[0])) {
                        return false;
                    }
                    $routeInfo = $args[0];
                    $resourceName = null;
                    if (isset($routeInfo[1]['uses'])) {
                        $rootSpan->setTag('lumen.route.action', $routeInfo[1]['uses']);
                        $resourceName = $routeInfo[1]['uses'];
                    }
                    if (isset($routeInfo[1]['as'])) {
                        $rootSpan->setTag('lumen.route.name', $routeInfo[1]['as']);
                        $resourceName = $routeInfo[1]['as'];
                    }

                    if (null !== $resourceName) {
                        $rootSpan->setTag(
                            Tag::RESOURCE_NAME,
                            $rootSpan->getTag(Tag::HTTP_METHOD) . ' ' . $resourceName
                        );
                    }

                    return false;
                },
            ]
        );

        $exceptionRenderer = function (SpanData $span, $args) use ($rootSpan) {
            if (count($args) < 1 || !\is_a($args[0], 'Throwable')) {
                return false;
            }
            $exception = $args[0];
            $rootSpan->setError($exception);
            return false;
        };

        \dd_trace_method('Laravel\Lumen\Application', 'handleUncaughtException', [ 'prehook' => $exceptionRenderer]);
        \dd_trace_method('Laravel\Lumen\Application', 'sendExceptionToHandler', [ 'prehook' => $exceptionRenderer]);

        // View is rendered in laravel as the method name overlaps

        return SandboxedIntegration::LOADED;
    }
}
