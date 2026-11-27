<?php

namespace DDTrace\Integrations\Slim;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\SpanKind;
use DDTrace\Tag;
use DDTrace\Type;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

class SlimIntegration extends Integration
{
    const NAME = 'slim';

    /**
     * Add instrumentation to Slim requests
     */
    public static function init(): int
    {
        \DDTrace\hook_method(
            'Slim\App',
            '__construct',
            null,
            function ($app) {
                $majorVersion = substr($app::VERSION, 0, 1);
                if ('3' !== $majorVersion && '4' !== $majorVersion) {
                    return;
                }

                // Overwrite root span info
                $rootSpan = \DDTrace\root_span();
                $rootSpan->name = 'slim.request';
                $rootSpan->service = \dd_trace_env_config('DD_SERVICE') ?: SlimIntegration::NAME;
                Integration::tagFrameworkServiceSource($rootSpan, SlimIntegration::NAME);
                $rootSpan->spanKind = SpanKind::SERVER;
                $rootSpan->component = SlimIntegration::NAME;

                if ('4' === $majorVersion) {
                    \DDTrace\hook_method('Slim\\MiddlewareDispatcher', 'addMiddleware', function ($This, $self, $args) {
                        if (isset($args[0]) && \is_object($args[0])) {
                            $name = \get_class($args[0]);
                            $closure = function (SpanData $span) {
                                $span->name = 'slim.middleware';
                                $span->resource = \get_class($this);
                                $span->type = Type::WEB_SERVLET;
                                Integration::setComponentMetadata($span, SlimIntegration::NAME);
                            };
                            \DDTrace\trace_method($name, 'process', $closure);
                        }
                    });

                    /* Blocked: https://datadoghq.atlassian.net/browse/APMPHP-553
                    \DDTrace\hook_method(
                        'Slim\\Middleware\\ErrorMiddleware',
                        'handleException',
                        static function ($errorMiddleware, $self, $args) use ($rootSpan, $integration) {
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
                    // Hook into the router to extract the proper route name
                    \DDTrace\hook_method(
                        'Slim\\Router',
                        'lookupRoute',
                        null,
                        static function ($router, $scope, $args, $return) use ($rootSpan) {
                            /** @var \Slim\Interfaces\RouteInterface $return */
                            $rootSpan->attributes[Tag::HTTP_ROUTE] = $return->getPattern();

                            if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")) {
                                $rootSpan->resource =
                                    $_SERVER['REQUEST_METHOD'] . ' ' . ($return->getName() ?: $return->getPattern());
                            }

                            SlimIntegration::registerSlimEndpoints($router);
                        }
                    );

                    // Slim 3: Route::prepare(ServerRequestInterface $request, array $arguments) receives the
                    // URL-decoded arguments captured by the router for the matched route.
                    \DDTrace\hook_method(
                        'Slim\\Route',
                        'prepare',
                        static function ($route, $scope, $args) {
                            SlimIntegration::pushPathParams($args[1] ?? null);
                        }
                    );
                }
                else if ('4' === $majorVersion) {
                    \DDTrace\hook_method(
                        'Slim\\Routing\\RouteCollector',
                        'lookupRoute',
                        null,
                        static function ($router, $scope, $args, $return) use ($rootSpan) {
                            /** @var \Slim\Interfaces\RouteInterface $route */
                            $route = $return;
                            $rootSpan->attributes[Tag::HTTP_ROUTE] = $route->getPattern();

                            SlimIntegration::registerSlimEndpoints($router);
                        }
                    );

                    // Slim 4: Route::prepare(array $arguments) receives the URL-decoded arguments
                    // captured by the routing results for the matched route.
                    \DDTrace\hook_method(
                        'Slim\\Routing\\Route',
                        'prepare',
                        static function ($route, $scope, $args) {
                            SlimIntegration::pushPathParams($args[0] ?? null);
                        }
                    );
                }

                // Providing info about the controller
                $traceControllers = static function (SpanData $span, $args) use ($rootSpan, $majorVersion) {
                    $callable = $args[0];
                    $callableName = '{unknown callable}';
                    \is_callable($callable, false, $callableName);

                    // PHP 8.5 changed closure naming format; normalize to Closure::__invoke
                    if ($callable instanceof \Closure) {
                        $callableName = 'Closure::__invoke';
                    }

                    $span->resource = $callableName ?: 'controller';
                    $span->type = Type::WEB_SERVLET;
                    Integration::setComponentMetadata($span, SlimIntegration::NAME);

                    /** @var ServerRequestInterface $request */
                    $request = $args[1];

                    if (!Integration::hasTag($rootSpan, Tag::HTTP_URL)) {
                        $rootSpan->attributes[Tag::HTTP_URL] =
                                \DDTrace\Util\Normalizer::urlSanitize((string) $request->getUri());
                    }

                    if ('4' === $majorVersion) {
                        $span->name = 'slim.route';
                        $rootSpan->attributes['slim.route.handler'] = $callableName;

                        $route = $request->getAttribute(RouteContext::ROUTE);
                        if ($route && $route instanceof \Slim\Interfaces\RouteInterface) {
                            $routeName = $route->getName();
                            if ($routeName) {
                                $span->attributes['slim.route.name'] = $routeName;
                                $rootSpan->attributes['slim.route.name'] = $routeName;
                            }
                        }
                    } else {
                        $rootSpan->attributes['slim.route.controller'] = $callableName;
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
                \DDTrace\trace_method('Slim\Views\Twig', 'render', static function (SpanData $span, $args) {
                    $span->name = 'slim.view';
                    $span->service = \dd_trace_env_config('DD_SERVICE') ?: SlimIntegration::NAME;
                    Integration::tagFrameworkServiceSource($span, SlimIntegration::NAME);
                    $span->type = Type::WEB_SERVLET;
                    $template = $args[1];
                    $span->resource = $template;
                    $span->attributes['slim.view'] = $template;
                    $span->component = SlimIntegration::NAME;
                });
            }
        );

        return Integration::LOADED;
    }

    /**
     * Sends the route path parameters to the WAF. Static routes (no parameters) send nothing.
     *
     * @param mixed $arguments The arguments captured from the URL by the router.
     */
    public static function pushPathParams($arguments)
    {
        if (!\function_exists('datadog\appsec\push_addresses') || !\is_array($arguments) || empty($arguments)) {
            return;
        }

        \datadog\appsec\push_addresses(["server.request.path_params" => $arguments]);
    }

    /**
     * Publishes every registered route to telemetry as an app-endpoint. Both
     * Slim 3 (Slim\Router) and Slim 4 (Slim\Routing\RouteCollector) expose a
     * matching getRoutes()/getPattern()/getMethods() shape; group prefixes are
     * already folded into getPattern() by Slim at registration time.
     *
     * @param \Slim\Router|\Slim\Routing\RouteCollector $router
     */
    public static function registerSlimEndpoints($router)
    {
        if (\DDTrace\are_endpoints_collected()) {
            return;
        }

        foreach ($router->getRoutes() as $route) {
            $pattern = $route->getPattern();
            foreach ($route->getMethods() as $method) {
                \DDTrace\add_endpoint($pattern, 'http.request', $method . ' ' . $pattern, $method);
            }
        }

        \DDTrace\flush_endpoints();
    }
}
