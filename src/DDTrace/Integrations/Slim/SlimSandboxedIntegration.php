<?php

namespace DDTrace\Integrations\Slim;

use DDTrace\GlobalTracer;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Versions;
use Psr\Http\Message\ServerRequestInterface;

class SlimSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'slim';

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * Add instrumentation to Slim requests
     */
    public function init()
    {
        // http://www.slimframework.com/docs/v3/start/installation.html
        if (\PHP_VERSION_ID < 50500) {
            return SandboxedIntegration::NOT_AVAILABLE;
        }

        $integration = $this;
        $appName = \ddtrace_config_app_name(self::NAME);

        \DDTrace\hook_method(
            'Slim\App',
            '__construct',
            null,
            function ($app) use ($integration, $appName) {
                // At the moment we only report internals of Slim 3.*
                $majorVersion = substr($app::VERSION, 0, 1);
                if ('3' !== $majorVersion) {
                    return;
                }

                // Overwrite root span info
                $rootSpan = GlobalTracer::get()->getRootScope()->getSpan();
                $integration->addTraceAnalyticsIfEnabledLegacy($rootSpan);
                $rootSpan->overwriteOperationName('slim.request');
                $rootSpan->setTag(Tag::SERVICE_NAME, $appName);

                // Hook into the router to extract the proper route name
                \DDTrace\hook_method(
                    'Slim\Router',
                    'lookupRoute',
                    null,
                    function ($router, $scope, $args, $return) use ($rootSpan) {
                        /** @var \Slim\Interfaces\RouteInterface $route */
                        $route = $return;
                        $rootSpan->setTag(
                            Tag::RESOURCE_NAME,
                            $_SERVER['REQUEST_METHOD'] . ' ' . ($route->getName() ?: $route->getPattern())
                        );
                    }
                );

                // Providing info about the controller
                $traceControllers = function (SpanData $span, $args) use ($rootSpan, $appName) {
                    $callable = $args[0];
                    $callableName = '{unknown callable}';
                    \is_callable($callable, false, $callableName);
                    $rootSpan->setTag('slim.route.controller', $callableName);

                    $span->name = 'slim.route.controller';
                    $span->resource = $callableName ?: 'controller';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $appName;

                    /** @var ServerRequestInterface $request */
                    $request = $args[1];
                    $rootSpan->setTag(Tag::HTTP_URL, (string) $request->getUri());
                };

                // If the tracer ever supports tracing an interface, we should trace the following:
                // Slim\Interfaces\InvocationStrategyInterface::__invoke
                \DDTrace\trace_method('Slim\Handlers\Strategies\RequestResponse', '__invoke', [
                    'prehook' => $traceControllers,
                ]);
                \DDTrace\trace_method('Slim\Handlers\Strategies\RequestResponseArgs', '__invoke', [
                    'prehook' => $traceControllers,
                ]);

                // Handling Twig views
                \DDTrace\trace_method('Slim\Views\Twig', 'render', function (SpanData $span, $args) use ($appName) {
                    $span->name = 'slim.view';
                    $span->service = $appName;
                    $span->type = Type::WEB_SERVLET;
                    $template = $args[1];
                    $span->resource = $template;
                    $span->meta['slim.view'] = $template;
                });
            }
        );

        return SandboxedIntegration::LOADED;
    }
}
