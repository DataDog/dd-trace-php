<?php

namespace DDTrace\Integrations\Slim;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

class SlimIntegration extends Integration
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
            return Integration::NOT_AVAILABLE;
        }

        $integration = $this;
        $appName = \ddtrace_config_app_name(self::NAME);

        \DDTrace\hook_method(
            'Slim\App',
            '__construct',
            null,
            function ($app) use ($integration, $appName) {
                $majorVersion = substr($app::VERSION, 0, 1);
                if ('3' !== $majorVersion && '4' !== $majorVersion) {
                    return;
                }

                // Overwrite root span info
                $rootSpan = \DDTrace\root_span();
                $integration->addTraceAnalyticsIfEnabled($rootSpan);
                $rootSpan->service = $appName;

                if ('4' === $majorVersion) {
                    \DDTrace\hook_method('Slim\\MiddlewareDispatcher', 'addMiddleware', function ($This, $self, $args) {
                        if (isset($args[0]) && \is_object($args[0])) {
                            $name = \get_class($args[0]);
                            $closure = function (SpanData $span) {
                                $span->name = 'slim.middleware';
                                $span->resource = \get_class($this);
                                $span->type = Type::WEB_SERVLET;
                                $span->service = \ddtrace_config_app_name(SlimIntegration::NAME);
                            };
                            \DDTrace\trace_method($name, 'process', $closure);
                        }
                    });

                    /* Blocked: https://datadoghq.atlassian.net/browse/APMPHP-553
                    \DDTrace\hook_method(
                        'Slim\\Middleware\\ErrorMiddleware',
                        'handleException',
                        function ($errorMiddleware, $self, $args) use ($rootSpan, $integration) {
                            if (isset($args[1])) {
                                $throwable = $args[1];
                                if ($throwable instanceof \Throwable) {
                                    $integration->setError($rootSpan);
                                }
                            }
                        }
                    );
                     */
                }

                if ('3' === $majorVersion) {
                    $rootSpan->name = 'slim.request';

                    // Hook into the router to extract the proper route name
                    \DDTrace\hook_method(
                        'Slim\\Router',
                        'lookupRoute',
                        null,
                        function ($router, $scope, $args, $return) use ($rootSpan) {
                            /** @var \Slim\Interfaces\RouteInterface $route */
                            $route = $return;
                            $rootSpan->resource =
                                $_SERVER['REQUEST_METHOD'] . ' ' . ($route->getName() ?: $route->getPattern());
                        }
                    );
                }

                // Providing info about the controller
                $traceControllers = function (SpanData $span, $args) use ($rootSpan, $appName, $majorVersion) {
                    $callable = $args[0];
                    $callableName = '{unknown callable}';
                    \is_callable($callable, false, $callableName);

                    $span->resource = $callableName ?: 'controller';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $appName;

                    /** @var ServerRequestInterface $request */
                    $request = $args[1];
                    $rootSpan->meta[Tag::HTTP_URL] = (string) $request->getUri();

                    if ('4' === $majorVersion) {
                        $span->name = 'slim.route';
                        $rootSpan->meta['slim.route.handler'] = $callableName;

                        $route = $request->getAttribute(RouteContext::ROUTE);
                        if ($route && $route instanceof \Slim\Interfaces\RouteInterface) {
                            $routeName = $route->getName();
                            if ($routeName) {
                                $span->meta['slim.route.name'] = $routeName;
                                $rootSpan->meta['slim.route.name'] = $routeName;
                            }
                        }
                    } else {
                        $rootSpan->meta['slim.route.controller'] = $callableName;
                        $span->name = 'slim.route.controller';
                    }
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

        return Integration::LOADED;
    }
}
