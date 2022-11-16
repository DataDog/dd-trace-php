<?php

namespace DDTrace\Integrations\Symfony;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use DDTrace\Util\Versions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;

class SymfonyIntegration extends Integration
{
    const NAME = 'symfony';

    /** @var SpanData */
    public $symfonyRequestSpan;

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
        \DDTrace\trace_method(
            'Symfony\Component\HttpKernel\Kernel',
            'handle',
            [
                'prehook' => function (SpanData $span) {
                    $rootSpan = \DDTrace\root_span();
                    if ($rootSpan === $span) {
                        return false;
                    }

                    $service = \ddtrace_config_app_name('symfony');
                    $rootSpan->name = 'symfony.request';
                    $rootSpan->service = $service;
                    $rootSpan->meta[Tag::SPAN_KIND] = 'server';

                    $span->name = 'symfony.httpkernel.kernel.handle';
                    $span->resource = \get_class($this);
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $service;
                    $span->meta[Tag::SPAN_KIND] = 'server';
                },
            ]
        );

        \DDTrace\trace_method(
            'Symfony\Component\HttpKernel\Kernel',
            'boot',
            [
                'prehook' => function (SpanData $span) {
                    if (\DDTrace\root_span() === $span) {
                        return false;
                    }

                    $span->name = 'symfony.httpkernel.kernel.boot';
                    $span->resource = \get_class($this);
                    $span->type = Type::WEB_SERVLET;
                    $span->service = \ddtrace_config_app_name('symfony');
                },
            ]
        );

        $symfonyCommandsIntegrated = [];
        \DDTrace\hook_method(
            'Symfony\Component\Console\Command\Command',
            '__construct',
            null,
            function ($This, $scope) use (&$symfonyCommandsIntegrated) {
                if (isset($symfonyCommandsIntegrated[$scope])) {
                    return;
                }

                $symfonyCommandsIntegrated[$scope] = true;

                \DDTrace\trace_method($scope, 'run', [
                    /* Commands can evidently call other commands, so allow recursion:
                     * > Console events are only triggered by the main command being executed.
                     * > Commands called by the main command will not trigger any event.
                     * - https://symfony.com/doc/current/components/console/events.html.
                     */
                    'recurse' => true,
                    'prehook' => function (SpanData $span) use ($scope) {
                        if (\DDTrace\root_span() === $span) {
                            return false;
                        }
                        $span->name = 'symfony.console.command.run';
                        $span->resource = $this->getName() ?: $span->name;
                        $span->service = \ddtrace_config_app_name('symfony');
                        $span->type = Type::CLI;
                        $span->meta['symfony.console.command.class'] = $scope;
                    }]);
            }
        );

        $rootSpan = \DDTrace\root_span();
        if (null == $rootSpan) {
            return Integration::NOT_LOADED;
        }

        /** @var SpanData $symfonyRequestSpan */
        $this->symfonyRequestSpan = $rootSpan;
        $this->addTraceAnalyticsIfEnabled($rootSpan);

        if (
            defined('\Symfony\Component\HttpKernel\Kernel::VERSION')
            && Versions::versionMatches('2', \Symfony\Component\HttpKernel\Kernel::VERSION)
        ) {
            $this->loadSymfony2($this);
        } else {
            $this->loadSymfony($this);
        }

        return Integration::LOADED;
    }

    public function loadSymfony($integration)
    {
        /* Move this to its own integration
        $doctrineRepositories = [];
        \DDTrace\hook_method(
            'Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository',
            '__construct',
            function ($object, $class) use (&$doctrineRepositories) {
                if (isset($object) && \is_object($object)) {
                    $class = \get_class($object);
                }

                if (isset($doctrineRepositories[$class])) {
                    return;
                }

                $doctrineRepositories[$class] = null;

                $rc = new \ReflectionClass($class);
                $methods = $rc->getMethods(\ReflectionMethod::IS_PUBLIC);
                foreach ($methods as $method) {
                    $methodname = $method->name;
                    // Skip magic methods and other functions prefixed with _
                    if (\strlen($methodname) === 0 || $methodname[0] === '_') {
                        continue;
                    }

                    \DDTrace\trace_method(
                        $class,
                        $methodname,
                        function (SpanData $span) use ($class, $methodname) {
                            $replaced = \str_replace('\\', '.', $class);
                            $span->name = "{$replaced}.{$methodname}";
                            $span->resource = "{$class}::{$methodname}";
                            $span->type = Type::WEB_SERVLET;
                            $span->service = \ddtrace_config_app_name('doctrine');
                        }
                    );
                }
            }
        );
         */

        \DDTrace\trace_method(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handle',
            function (SpanData $span, $args, $response) use ($integration) {
                /** @var Request $request */
                list($request) = $args;

                $span->name = $span->resource = 'symfony.kernel.handle';
                $span->service = \ddtrace_config_app_name('symfony');
                $span->type = Type::WEB_SERVLET;

                $integration->symfonyRequestSpan->meta[Tag::HTTP_METHOD] = $request->getMethod();

                if (!array_key_exists(Tag::HTTP_URL, $integration->symfonyRequestSpan->meta)) {
                    $integration->symfonyRequestSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize($request->getUri());
                }
                if (isset($response)) {
                    $integration->symfonyRequestSpan->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                }

                $route = $request->get('_route');
                if (null !== $route && null !== $request) {
                    $integration->symfonyRequestSpan->resource = $route;
                    $integration->symfonyRequestSpan->meta['symfony.route.name'] = $route;
                }
            }
        );

        /*
         * EventDispatcher v4.3 introduced an arg hack that mutates the arguments.
         * @see https://github.com/symfony/event-dispatcher/blob/4.3/EventDispatcher.php#L51-L64
         * Since the arguments passed to the tracing closure on PHP 7 are mutable,
         * the closure must be run _before_ the original call via 'prehook'.
        */
        \DDTrace\trace_method(
            'Symfony\Component\EventDispatcher\EventDispatcher',
            'dispatch',
            [
                'recurse' => true,
                'prehook' => function (SpanData $span, $args) use ($integration, &$injectedActionInfo) {
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

                    // trace the container itself
                    if ($eventName === 'kernel.controller' && \method_exists($event, 'getController')) {
                        $controller = $event->getController();
                        if (!($controller instanceof \Closure)) {
                            if (\is_callable($controller, false, $controllerName) && $controllerName !== null) {
                                if (\strpos($controllerName, '::') > 0) {
                                    list($class, $method) = \explode('::', $controllerName);
                                    if (isset($class, $method)) {
                                        \DDTrace\trace_method(
                                            $class,
                                            $method,
                                            function (SpanData $span) use ($controllerName) {
                                                $span->name = 'symfony.controller';
                                                $span->resource = $controllerName;
                                                $span->type = Type::WEB_SERVLET;
                                                $span->service = \ddtrace_config_app_name('symfony');
                                            }
                                        );
                                    }
                                } else {
                                    \DDTrace\trace_function(
                                        $controllerName,
                                        function (SpanData $span) use ($controllerName) {
                                            $span->name = 'symfony.controller';
                                            $span->resource = $controllerName;
                                            $span->type = Type::WEB_SERVLET;
                                            $span->service = \ddtrace_config_app_name('symfony');
                                        }
                                    );
                                }
                            }
                        }
                    }

                    $span->name = $span->resource = 'symfony.' . $eventName;
                    $span->service = \ddtrace_config_app_name('symfony');
                    if ($event === null) {
                        return;
                    }
                    if (!$injectedActionInfo) {
                        if ($integration->injectActionInfo($event, $eventName, $integration->symfonyRequestSpan)) {
                            $injectedActionInfo = true;
                        }
                    }
                }
            ]
        );

        // Handling exceptions
        $exceptionHandlingTracer = function (SpanData $span, $args, $retval) use ($integration) {
            $span->name = $span->resource = 'symfony.kernel.handleException';
            $span->service = \ddtrace_config_app_name('symfony');
            if (!(isset($retval) && \method_exists($retval, 'getStatusCode') && $retval->getStatusCode() < 500)) {
                $integration->setError($integration->symfonyRequestSpan, $args[0]);
            }
        };
        // Symfony 4.3-
        \DDTrace\trace_method('Symfony\Component\HttpKernel\HttpKernel', 'handleException', $exceptionHandlingTracer);
        // Symfony 4.4+
        \DDTrace\trace_method('Symfony\Component\HttpKernel\HttpKernel', 'handleThrowable', $exceptionHandlingTracer);

        // Tracing templating engines
        $traceRender = function (SpanData $span, $args) {
            $span->name = 'symfony.templating.render';
            $span->service = \ddtrace_config_app_name('symfony');
            $span->type = Type::WEB_SERVLET;

            $resourceName = count($args) > 0 ? get_class($this) . ' ' . $args[0] : get_class($this);
            $span->resource = $resourceName;
        };
        \DDTrace\trace_method('Symfony\Bridge\Twig\TwigEngine', 'render', $traceRender);
        \DDTrace\trace_method('Symfony\Bundle\FrameworkBundle\Templating\TimedPhpEngine', 'render', $traceRender);
        \DDTrace\trace_method('Symfony\Component\Templating\DelegatingEngine', 'render', $traceRender);
        \DDTrace\trace_method('Symfony\Component\Templating\PhpEngine', 'render', $traceRender);
        \DDTrace\trace_method('Twig\Environment', 'render', $traceRender);
    }

    public function loadSymfony2($integration)
    {
        // Symfony 2.x specific resource name assignment
        \DDTrace\trace_method(
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
                    if (count($resourceParts) > 0) {
                        $integration->symfonyRequestSpan->resource = \implode(' ', $resourceParts);
                    }
                }

                return false;
            }
        );
    }

    /**
     * @param \Symfony\Component\EventDispatcher\Event $event
     * @param string $eventName
     * @param SpanData $requestSpan
     */
    public function injectActionInfo($event, $eventName, SpanData $requestSpan)
    {
        if (
            !\defined("\Symfony\Component\HttpKernel\KernelEvents::CONTROLLER")
            || $eventName !== KernelEvents::CONTROLLER
            || !method_exists($event, 'getController')
        ) {
            return false;
        }

        // Controller and action is provided in the form [$controllerInstance, <actionMethodName>]
        $controllerAndAction = $event->getController();

        if (
            !is_array($controllerAndAction)
            || count($controllerAndAction) !== 2
            || !is_object($controllerAndAction[0])
        ) {
            return false;
        }

        $action = get_class($controllerAndAction[0]) . '@' . $controllerAndAction[1];
        $requestSpan->meta['symfony.route.action'] = $action;

        return true;
    }
}
