<?php

namespace DDTrace\Integrations\Symfony;

use DDTrace\HookData;
use DDTrace\Integrations\Drupal\DrupalIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;

class SymfonyIntegration extends Integration
{
    const NAME = 'symfony';

    /** @var SpanData */
    public $symfonyRequestSpan;

    /** @var string */
    public $frameworkPrefix = SymfonyIntegration::NAME;

    /**
     * {@inheritdoc}
     */
    public function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    /**
     * Load the integration
     *
     * @return int
     */
    public function init(): int
    {
        $integration = $this;

        \DDTrace\trace_method(
            'Symfony\Component\HttpKernel\Kernel',
            'handle',
            [
                'prehook' => function (SpanData $span) use ($integration) {
                    $rootSpan = \DDTrace\root_span();
                    if ($rootSpan === $span) {
                        return false;
                    }

                    $service = \ddtrace_config_app_name('symfony');
                    $rootSpan->name = 'symfony.request';
                    $rootSpan->service = $service;
                    $rootSpan->meta[Tag::SPAN_KIND] = 'server';
                    $rootSpan->meta[Tag::COMPONENT] = SymfonyIntegration::NAME;
                    $integration->addTraceAnalyticsIfEnabled($rootSpan);

                    $span->name = 'symfony.httpkernel.kernel.handle';
                    $span->resource = \get_class($this);
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $service;
                    $span->meta[Tag::SPAN_KIND] = 'server';
                    $span->meta[Tag::COMPONENT] = SymfonyIntegration::NAME;
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
                    $span->meta[Tag::COMPONENT] = SymfonyIntegration::NAME;
                },
            ]
        );

        \DDTrace\hook_method(
            'Doctrine\ORM\UnitOfWork',
            'executeInserts',
            function ($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_signup_event')) {
                    return;
                }

                $entities = \method_exists($This, 'getScheduledEntityInsertions') ?
                    $This->getScheduledEntityInsertions() :
                    [];
                $userInterface = 'Symfony\Component\Security\Core\User\UserInterface';
                $found = 0;
                $userEntity = null;
                foreach ($entities as $entity) {
                    if (!($entity instanceof $userInterface)) {
                        continue;
                    }
                    $found++;
                    $userEntity = $entity;
                }

                if ($found != 1) {
                    return;
                }

                $user = null;
                if (\method_exists($userEntity, 'getUsername')) {
                    $user = $userEntity->getUsername();
                } elseif (\method_exists($userEntity, 'getUserIdentifier')) {
                    $user = $userEntity->getUserIdentifier();
                }

                \datadog\appsec\track_user_signup_event($user, [], true);
            }
        );

        //Symfony < 5
        \DDTrace\hook_method(
            'Symfony\Component\Security\Guard\Authenticator\AbstractFormLoginAuthenticator',
            'onAuthenticationSuccess',
            function ($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_login_success_event')) {
                    return;
                }
                if (!isset($args[1])) {
                    return;
                }
                $token = $args[1];
                $authClass = '\Symfony\Component\Security\Core\Authentication\Token\TokenInterface';
                if (!$token || !($token instanceof $authClass)) {
                    return;
                }
                $metadata = [];

                \datadog\appsec\track_user_login_success_event(
                    \method_exists($token, 'getUsername') ? $token->getUsername() : '',
                    $metadata,
                    true
                );
            }
        );

        //Symfony < 5
        \DDTrace\hook_method(
            'Symfony\Component\Security\Guard\Authenticator\AbstractFormLoginAuthenticator',
            'onAuthenticationFailure',
            function ($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_login_failure_event')) {
                    return;
                }
                \datadog\appsec\track_user_login_failure_event(null, false, [], true);
            }
        );

        //Symfony >= 5
        \DDTrace\hook_method(
            'Symfony\Component\Security\Http\Firewall\AbstractAuthenticationListener',
            'onFailure',
            function ($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_login_failure_event')) {
                    return;
                }
                \datadog\appsec\track_user_login_failure_event(null, false, [], true);
            }
        );

        //Symfony >= 5 and < 6
        \DDTrace\hook_method(
            'Symfony\Component\Security\Http\Firewall\AbstractAuthenticationListener',
            'onSuccess',
            function ($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_login_success_event')) {
                    return;
                }
                if (!isset($args[1])) {
                    return;
                }
                $token = $args[1];
                $authClass = '\Symfony\Component\Security\Core\Authentication\Token\TokenInterface';
                if (!$token || !($token instanceof $authClass)) {
                    return;
                }

                $metadata = [];

                \datadog\appsec\track_user_login_success_event(
                    \method_exists($token, 'getUsername') ? $token->getUsername() : '',
                    $metadata,
                    true
                );
            }
        );

        //Symfony >= 6
        \DDTrace\hook_method(
            'Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator',
            'onAuthenticationFailure',
            function ($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_login_failure_event')) {
                    return;
                }
                \datadog\appsec\track_user_login_failure_event(null, false, [], true);
            }
        );

        //Symfony >= 6
        \DDTrace\hook_method(
            'Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator',
            'onAuthenticationSuccess',
            function ($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_login_success_event')) {
                    return;
                }
                if (!isset($args[1])) {
                    return;
                }
                $token = $args[1];
                $authClass = '\Symfony\Component\Security\Core\Authentication\Token\TokenInterface';
                if (!$token || !($token instanceof $authClass)) {
                    return;
                }
                $metadata = [];

                $user = \method_exists($token, 'getUser') ? $token->getUser() : null;
                $userClass = '\Symfony\Component\Security\Core\User\UserInterface';
                if (!$user || !($user instanceof $userClass)) {
                    return;
                }
                \datadog\appsec\track_user_login_success_event(
                    \method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : '',
                    $metadata,
                    true
                );
            }
        );

        \DDTrace\trace_method(
            'Symfony\Component\Console\Command\Command',
            'run',
            [
                /* Commands can evidently call other commands, so allow recursion:
                 * > Console events are only triggered by the main command being executed.
                 * > Commands called by the main command will not trigger any event.
                 * - https://symfony.com/doc/current/components/console/events.html.
                 */
                'recurse' => true,
                'prehook' => function (SpanData $span) use ($integration) {
                    if (\DDTrace\root_span() === $span) {
                        return false;
                    }

                    $commandName = $this->getName();

                    if (\dd_trace_env_config('DD_TRACE_REMOVE_ROOT_SPAN_SYMFONY_MESSENGER')
                        && $commandName === 'messenger:consume'
                    ) {
                        \dd_trace_close_all_spans_and_flush();
                        ini_set("datadog.trace.auto_flush_enabled", 1);
                        ini_set("datadog.trace.generate_root_span", 0);
                        return false;
                    }

                    $namespace = \get_class($this);
                    if (strpos($namespace, DrupalIntegration::NAME) !== false) {
                        $integration->frameworkPrefix = DrupalIntegration::NAME;
                    } else {
                        $integration->frameworkPrefix = SymfonyIntegration::NAME;
                    }

                    $span->name = 'symfony.console.command.run';
                    $span->resource = $$commandName ?: $span->name;
                    $span->service = \ddtrace_config_app_name($integration->frameworkPrefix);
                    $span->type = Type::CLI;
                    $span->meta['symfony.console.command.class'] = \get_class($this);
                    $span->meta[Tag::COMPONENT] = SymfonyIntegration::NAME;
                }
            ]
        );

        \DDTrace\hook_method(
            'Symfony\Component\Console\Application',
            'renderException',
            function ($This, $scope, $args) use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan !== null) {
                    $rootSpan->exception = $args[0];
                }
            }
        );

        \DDTrace\hook_method(
            'Symfony\Component\Console\Application',
            'renderThrowable',
            function ($This, $scope, $args) use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan !== null) {
                    $rootSpan->exception = $args[0];
                }
            }
        );

        $this->loadSymfony($this);

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

        \DDTrace\hook_method(
            'Symfony\Component\HttpKernel\HttpKernel',
            '__construct',
            function () use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan && strpos($rootSpan->name, DrupalIntegration::NAME) !== false) {
                    $integration->frameworkPrefix = DrupalIntegration::NAME;
                }
            }
        );

        \DDTrace\trace_method(
            'Symfony\Component\HttpKernel\HttpKernel',
            'handle',
            function (SpanData $span, $args, $response) use ($integration) {
                /** @var Request $request */
                list($request) = $args;

                $span->name = 'symfony.kernel.handle';
                $span->service = \ddtrace_config_app_name($integration->frameworkPrefix);
                $span->type = Type::WEB_SERVLET;
                $span->meta[Tag::COMPONENT] = SymfonyIntegration::NAME;

                $rootSpan = \DDTrace\root_span();
                $rootSpan->meta[Tag::HTTP_METHOD] = $request->getMethod();
                $rootSpan->meta[Tag::COMPONENT] = $integration->frameworkPrefix;
                $rootSpan->meta[Tag::SPAN_KIND] = 'server';
                $integration->addTraceAnalyticsIfEnabled($rootSpan);

                if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                    $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize($request->getUri());
                }
                if (isset($response)) {
                    $rootSpan->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                }

                $parameters = $request->get('_route_params');
                if (!empty($parameters) &&
                    is_array($parameters) &&
                    function_exists('\datadog\appsec\push_address')) {
                    \datadog\appsec\push_address("server.request.path_params", $parameters);
                }

                $route = $request->get('_route');
                if (null !== $route && null !== $request) {
                    if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")) {
                        $rootSpan->resource = $route;
                    }
                    $rootSpan->meta['symfony.route.name'] = $route;
                }
            }
        );

        /*
         * EventDispatcher v4.3 introduced an arg hack that mutates the arguments.
         * @see https://github.com/symfony/event-dispatcher/blob/4.3/EventDispatcher.php#L51-L64
         * Since the arguments passed to the tracing closure on PHP 7 are mutable,
         * the closure must be run _before_ the original call via 'prehook'.
        */
        $eventDispatcherTracer = [
            'recurse' => true,
            'prehook' => function (SpanData $span, $args) use ($integration, &$injectedActionInfo) {
                if (\DDTrace\root_span() === $span) {
                    return false; // e.g., lone symfony.console.terminate
                }

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
                                    \DDTrace\install_hook(
                                        "$class::$method",
                                        function (HookData $hook) use ($controllerName, $integration) {
                                            $span = $hook->span();
                                            $span->name = 'symfony.controller';
                                            $span->resource = $controllerName;
                                            $span->type = Type::WEB_SERVLET;
                                            $span->service = \ddtrace_config_app_name($integration->frameworkPrefix);
                                            $span->meta[Tag::COMPONENT] = SymfonyIntegration::NAME;

                                            \DDTrace\remove_hook($hook->id);
                                        }
                                    );
                                }
                            } else {
                                \DDTrace\install_hook(
                                    "$controllerName",
                                    function (HookData $hook) use ($controllerName, $integration) {
                                        $span = $hook->span();
                                        $span->name = 'symfony.controller';
                                        $span->resource = $controllerName;
                                        $span->type = Type::WEB_SERVLET;
                                        $span->service = \ddtrace_config_app_name($integration->frameworkPrefix);
                                        $span->meta[Tag::COMPONENT] = SymfonyIntegration::NAME;

                                        \DDTrace\remove_hook($hook->id);
                                    }
                                );
                            }
                        }
                    }
                }

                $span->name = $span->resource = 'symfony.' . $eventName;
                $span->service = \ddtrace_config_app_name($integration->frameworkPrefix);
                $span->meta[Tag::COMPONENT] = SymfonyIntegration::NAME;
                if ($event === null) {
                    return;
                }
                if (!$injectedActionInfo) {
                    $rootSpan = \DDTrace\root_span();
                    if ($integration->injectActionInfo($event, $eventName, $rootSpan)) {
                        $injectedActionInfo = true;
                    }
                }
            }
        ];
        \DDTrace\trace_method(
            'Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher',
            'dispatch',
            $eventDispatcherTracer
        );
        \DDTrace\trace_method(
            'Symfony\Component\EventDispatcher\EventDispatcher',
            'dispatch',
            $eventDispatcherTracer
        );



        // Handling exceptions
        $exceptionHandlingTracer = function (SpanData $span, $args, $retval) use ($integration) {
            $span->name = $span->resource = 'symfony.kernel.handleException';
            $span->service = \ddtrace_config_app_name($integration->frameworkPrefix);
            $span->meta[Tag::COMPONENT] = SymfonyIntegration::NAME;
            if (!(isset($retval) && \method_exists($retval, 'getStatusCode') && $retval->getStatusCode() < 500)) {
                \DDTrace\root_span()->exception = $args[0];
            }
        };
        // Symfony 4.3-
        \DDTrace\trace_method('Symfony\Component\HttpKernel\HttpKernel', 'handleException', $exceptionHandlingTracer);
        // Symfony 4.4+
        \DDTrace\trace_method('Symfony\Component\HttpKernel\HttpKernel', 'handleThrowable', $exceptionHandlingTracer);

        // Tracing templating engines
        $traceRender = function (SpanData $span, $args) use ($integration) {
            $span->name = 'symfony.templating.render';
            $span->service = \ddtrace_config_app_name($integration->frameworkPrefix);
            $span->type = Type::WEB_SERVLET;

            $resourceName = count($args) > 0 ? get_class($this) . ' ' . $args[0] : get_class($this);
            $span->resource = $resourceName;
            $span->meta[Tag::COMPONENT] = SymfonyIntegration::NAME;
        };
        \DDTrace\trace_method('Symfony\Bridge\Twig\TwigEngine', 'render', $traceRender);
        \DDTrace\trace_method('Symfony\Bundle\FrameworkBundle\Templating\TimedPhpEngine', 'render', $traceRender);
        \DDTrace\trace_method('Symfony\Component\Templating\DelegatingEngine', 'render', $traceRender);
        \DDTrace\trace_method('Symfony\Component\Templating\PhpEngine', 'render', $traceRender);
        \DDTrace\trace_method('Twig\Environment', 'render', $traceRender);
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
