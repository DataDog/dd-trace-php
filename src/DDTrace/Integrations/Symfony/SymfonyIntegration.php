<?php

namespace DDTrace\Integrations\Symfony;

use DDTrace\HookData;
use DDTrace\Integrations\Drupal\DrupalIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\ItemInterface;

class SymfonyIntegration extends Integration
{
    const NAME = 'symfony';

    /** @var string */
    public static $frameworkPrefix = SymfonyIntegration::NAME;

    public static $kernel;

    /**
     * {@inheritdoc}
     */
    public static function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    /**
     * Load the integration
     *
     * @return int
     */
    public static function init(): int
    {
        \DDTrace\trace_method(
            'Symfony\Component\HttpKernel\Kernel',
            'handle',
            [
                'prehook' => function(SpanData $span) {
                    $rootSpan = \DDTrace\root_span();
                    if ($rootSpan === $span) {
                        return false;
                    }

                    $service = \ddtrace_config_app_name('symfony');
                    $rootSpan->name = 'symfony.request';
                    $rootSpan->service = $service;
                    $rootSpan->meta[Tag::SPAN_KIND] = 'server';
                    $rootSpan->meta[Tag::COMPONENT] = SymfonyIntegration::NAME;
                    SymfonyIntegration::addTraceAnalyticsIfEnabled($rootSpan);

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
                'prehook' => function(SpanData $span) {
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
            static function($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_signup_event_automated')) {
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

                \datadog\appsec\track_user_signup_event_automated($user, $user, []);
            }
        );

        //Symfony < 5
        \DDTrace\hook_method(
            'Symfony\Component\Security\Guard\Authenticator\AbstractFormLoginAuthenticator',
            'onAuthenticationSuccess',
            static function($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_login_success_event_automated')) {
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
                $user = \method_exists($token, 'getUsername') ? $token->getUsername() : '';

                \datadog\appsec\track_user_login_success_event_automated(
                    $user,
                    $user,
                    $metadata
                );
            }
        );

        //Symfony < 5
        \DDTrace\hook_method(
            'Symfony\Component\Security\Guard\Authenticator\AbstractFormLoginAuthenticator',
            'onAuthenticationFailure',
            static function($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_login_failure_event_automated')) {
                    return;
                }
                \datadog\appsec\track_user_login_failure_event_automated(null, null, false, []);
            }
        );

        //Symfony >= 5
        \DDTrace\hook_method(
            'Symfony\Component\Security\Http\Firewall\AbstractAuthenticationListener',
            'onFailure',
            static function($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_login_failure_event_automated')) {
                    return;
                }
                \datadog\appsec\track_user_login_failure_event_automated(null, null, false, []);
            }
        );

        //Symfony >= 5 and < 6
        \DDTrace\hook_method(
            'Symfony\Component\Security\Http\Firewall\AbstractAuthenticationListener',
            'onSuccess',
            static function($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_login_success_event_automated')) {
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
                $user = \method_exists($token, 'getUsername') ? $token->getUsername() : '';

                \datadog\appsec\track_user_login_success_event_automated(
                    $user,
                    $user,
                    $metadata
                );
            }
        );

        //Symfony >= 6
        \DDTrace\hook_method(
            'Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator',
            'onAuthenticationFailure',
            static function($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_login_failure_event_automated')) {
                    return;
                }
                \datadog\appsec\track_user_login_failure_event_automated(null, null, false, []);
            }
        );

        //Symfony >= 6
        \DDTrace\hook_method(
            'Symfony\Component\Security\Http\Authenticator\FormLoginAuthenticator',
            'onAuthenticationSuccess',
            static function($This, $scope, $args) {
                if (!function_exists('\datadog\appsec\track_user_login_success_event_automated')) {
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

                $user = \method_exists($token, 'getUser') ? $token->getUser() : null;
                $userClass = '\Symfony\Component\Security\Core\User\UserInterface';
                if (!$user || !($user instanceof $userClass)) {
                    return;
                }

                $metadata = [];
                $userIdentifier = method_exists($user, 'getUserIdentifier')
                    ? $user->getUserIdentifier()
                    : (method_exists($user, 'getUsername') ? $user->getUsername() : '');

                \datadog\appsec\track_user_login_success_event_automated(
                    $userIdentifier,
                    $userIdentifier,
                    $metadata
                );
            }
        );

        \DDTrace\hook_method(
            'Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface',
            'decide',
            static function($This, $scope, $args, $result) {
                if (!function_exists('\datadog\appsec\track_authenticated_user_event_automated')) {
                    return;
                }

                // Extract the authentication token
                $token = $args[0];
                if (!$token) {
                    return;
                }

                // Extract user information
                $user = $token->getUser();
                if (!$user) {
                    return;
                }

                $userIdentifier = method_exists($user, 'getUserIdentifier')
                    ? $user->getUserIdentifier()
                    : (method_exists($user, 'getUsername') ? $user->getUsername() : '');

                // Track the access check
                \datadog\appsec\track_authenticated_user_event_automated($userIdentifier);
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
                'prehook' => function(SpanData $span) {
                    if (\DDTrace\root_span() === $span) {
                        return false;
                    }

                    $commandName = $this->getName();

                    if (\dd_trace_env_config('DD_TRACE_REMOVE_ROOT_SPAN_SYMFONY_MESSENGER')
                        && $commandName === 'messenger:consume'
                    ) {
                        \DDTrace\set_priority_sampling(DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT);
                        \dd_trace_close_all_spans_and_flush();
                        ini_set("datadog.trace.auto_flush_enabled", 1);
                        ini_set("datadog.trace.generate_root_span", 0);
                        return false;
                    }

                    $namespace = \get_class($this);
                    if (strpos($namespace, DrupalIntegration::NAME) !== false) {
                        SymfonyIntegration::$frameworkPrefix = DrupalIntegration::NAME;
                    } else {
                        SymfonyIntegration::$frameworkPrefix = SymfonyIntegration::NAME;
                    }

                    $span->name = 'symfony.console.command.run';
                    $span->resource = $commandName ?: $span->name;
                    $span->service = \ddtrace_config_app_name(SymfonyIntegration::$frameworkPrefix);
                    $span->type = Type::CLI;
                    $span->meta['symfony.console.command.class'] = \get_class($this);
                    $span->meta[Tag::COMPONENT] = SymfonyIntegration::NAME;
                }
            ]
        );

        \DDTrace\hook_method(
            'Symfony\Component\Console\Application',
            'renderException',
            static function($This, $scope, $args) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan !== null) {
                    $rootSpan->exception = $args[0];
                }
            }
        );

        \DDTrace\hook_method(
            'Symfony\Component\Console\Application',
            'renderThrowable',
            static function($This, $scope, $args) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan !== null) {
                    $rootSpan->exception = $args[0];
                }
            }
        );

        self::loadSymfony();

        return Integration::LOADED;
    }

    public static function loadSymfony()
    {
        /* Move this to its own integration
        $doctrineRepositories = [];
        \DDTrace\hook_method(
            'Doctrine\\Bundle\\DoctrineBundle\\Repository\\ServiceEntityRepository',
            '__construct',
            static function($object, $class) use (&$doctrineRepositories) {
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
                        static function(SpanData $span) use ($class, $methodname) {
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
            'Symfony\Component\HttpKernel\Kernel',
            'getHttpKernel',
            null,
            static function($object) {
                self::$kernel = $object;
            }
        );

        \DDTrace\hook_method(
            'Drupal\Core\DrupalKernel',
            'getHttpKernel',
            null,
            static function($object) {
                self::$kernel = $object;
            }
        );

        \DDTrace\hook_method(
            'Symfony\Component\HttpKernel\HttpKernel',
            '__construct',
            static function() {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan && strpos($rootSpan->name, DrupalIntegration::NAME) !== false) {
                    self::$frameworkPrefix = DrupalIntegration::NAME;
                }
            }
        );

        if (\dd_trace_env_config('DD_TRACE_SYMFONY_HTTP_ROUTE')) {
            /**
             * Resolves the http.route tag for a given route name by looking up
             * the route path in a cached map of all routes.
             *
             * Caching strategy:
             * - Caches the entire route path map under a single key: '_datadog.symfony.route_paths'
             * - Stores: ['mtime' => timestamp, 'paths' => ['route_name' => '/path', ...]]
             * - Invalidates cache when Symfony's compiled routes file is newer than cached mtime
             * - Falls back gracefully if cache.app is unavailable (no http.route tag)
             */
            $handle_http_route = static function($route_name, $request, $rootSpan) {
                if (self::$kernel === null) {
                    return;
                }

                /** @var ContainerInterface $container */
                $container = self::$kernel->getContainer();

                try {
                    $cache = $container->get('cache.app');
                } catch (\Exception $e) {
                    return;
                }

                if (!\method_exists($cache, 'getItem')) {
                    return;
                }

                /** @var \Symfony\Bundle\FrameworkBundle\Routing\Router $router */
                try {
                    $router = $container->get('router');
                } catch (\Exception $e) {
                    return;
                }

                // Get the compiled routes file mtime for cache invalidation
                $compiledRoutesMtime = null;
                $cacheDir = \method_exists($router, 'getOption') ? $router->getOption('cache_dir') : null;
                if ($cacheDir !== null) {
                    $compiledRoutesFile = $cacheDir . '/url_generating_routes.php';
                    if (\file_exists($compiledRoutesFile)) {
                        $compiledRoutesMtime = @\filemtime($compiledRoutesFile);
                    }
                }

                $cacheKey = '_datadog.symfony.route_paths';
                /** @var ItemInterface $item */
                $item = $cache->getItem($cacheKey);
                $cachedData = $item->isHit() ? $item->get() : null;

                $routePathMap = null;
                $needsRebuild = true;

                if (\is_array($cachedData) && isset($cachedData['paths']) && \is_array($cachedData['paths'])) {
                    // Check if cache is still valid
                    if ($compiledRoutesMtime === null) {
                        // No compiled file to check against - cache is valid
                        $needsRebuild = false;
                        $routePathMap = $cachedData['paths'];
                    } elseif (isset($cachedData['mtime']) && $cachedData['mtime'] >= $compiledRoutesMtime) {
                        // Cached data is newer than or equal to compiled routes - cache is valid
                        $needsRebuild = false;
                        $routePathMap = $cachedData['paths'];
                    }
                    // Otherwise: compiled routes file is newer, rebuild cache
                }

                if ($needsRebuild) {
                    $startTime = \function_exists('hrtime') ? \hrtime(true) : null;

                    $routePathMap = [];
                    $routeCollection = $router->getRouteCollection();
                    foreach ($routeCollection->all() as $name => $route) {
                        $routePathMap[$name] = $route->getPath();
                    }

                    if ($startTime !== null) {
                        $durationNanoseconds = \hrtime(true) - $startTime;
                        $durationMicroseconds = (int)($durationNanoseconds / 1000);
                        $rootSpan->metrics['_dd.symfony.route.map_build_duration_us'] = $durationMicroseconds;
                    }

                    $item->set([
                        'mtime' => \time(),
                        'paths' => $routePathMap,
                    ]);
                    $cache->save($item);
                }

                // Look up the route path
                $path = null;
                if (isset($routePathMap[$route_name])) {
                    $path = $routePathMap[$route_name];
                } else {
                    // Try with locale suffix (Symfony i18n routing convention)
                    $locale = $request->get('_locale');
                    if ($locale !== null && isset($routePathMap[$route_name . '.' . $locale])) {
                        $path = $routePathMap[$route_name . '.' . $locale];
                    }
                }

                if ($path !== null) {
                    $rootSpan->meta[Tag::HTTP_ROUTE] = $path;
                }
            };

            \DDTrace\trace_method(
                'Symfony\Component\HttpKernel\HttpKernel',
                'handle',
                static function(SpanData $span, $args, $response) use ($handle_http_route) {
                    /** @var Request $request */
                    list($request) = $args;

                    $span->name = 'symfony.kernel.handle';
                    $span->service = \ddtrace_config_app_name(self::$frameworkPrefix);
                    $span->type = Type::WEB_SERVLET;
                    $span->meta[Tag::COMPONENT] = self::NAME;

                    $rootSpan = \DDTrace\root_span();
                    $rootSpan->meta[Tag::HTTP_METHOD] = $request->getMethod();
                    $rootSpan->meta[Tag::COMPONENT] = self::$frameworkPrefix;
                    $rootSpan->meta[Tag::SPAN_KIND] = 'server';
                    self::addTraceAnalyticsIfEnabled($rootSpan);

                    if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                        $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize($request->getUri());
                    }
                    if (isset($response)) {
                        $rootSpan->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                    }

                    $route_name = $request->get('_route');
                    if ($route_name !== null) {
                        if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")) {
                            $rootSpan->resource = $route_name;
                        }
                        $rootSpan->meta['symfony.route.name'] = $route_name;
                        $handle_http_route($route_name, $request, $rootSpan);
                    }

                    $parameters = $request->get('_route_params');
                    if (!empty($parameters) &&
                        is_array($parameters) &&
                        function_exists('datadog\appsec\push_addresses')) {
                        \datadog\appsec\push_addresses(["server.request.path_params" => $parameters]);
                    }
                }
            );
        }

        /*
         * EventDispatcher v4.3 introduced an arg hack that mutates the arguments.
         * @see https://github.com/symfony/event-dispatcher/blob/4.3/EventDispatcher.php#L51-L64
         * Since the arguments passed to the tracing closure on PHP 7 are mutable,
         * the closure must be run _before_ the original call via 'prehook'.
        */
        $eventDispatcherTracer = [
            'recurse' => true,
            'prehook' => static function(SpanData $span, $args) use (&$injectedActionInfo) {
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
                                        static function(HookData $hook) use ($controllerName) {
                                            $span = $hook->span();
                                            $span->name = 'symfony.controller';
                                            $span->resource = $controllerName;
                                            $span->type = Type::WEB_SERVLET;
                                            $span->service = \ddtrace_config_app_name(self::$frameworkPrefix);
                                            $span->meta[Tag::COMPONENT] = self::NAME;

                                            \DDTrace\remove_hook($hook->id);
                                        }
                                    );
                                }
                            } else {
                                \DDTrace\install_hook(
                                    "$controllerName",
                                    static function(HookData $hook) use ($controllerName) {
                                        $span = $hook->span();
                                        $span->name = 'symfony.controller';
                                        $span->resource = $controllerName;
                                        $span->type = Type::WEB_SERVLET;
                                        $span->service = \ddtrace_config_app_name(self::$frameworkPrefix);
                                        $span->meta[Tag::COMPONENT] = self::NAME;

                                        \DDTrace\remove_hook($hook->id);
                                    }
                                );
                            }
                        }
                    }
                }

                $span->name = $span->resource = 'symfony.' . $eventName;
                $span->service = \ddtrace_config_app_name(self::$frameworkPrefix);
                $span->meta[Tag::COMPONENT] = self::NAME;
                if ($event === null) {
                    return;
                }
                if (!$injectedActionInfo) {
                    $rootSpan = \DDTrace\root_span();
                    if (self::injectActionInfo($event, $eventName, $rootSpan)) {
                        $injectedActionInfo = true;
                    }
                }

                if (self::$kernel !== null
                    && \defined(\get_class(self::$kernel) . '::VERSION')
                    && \strpos(self::$kernel::VERSION, '4.') !== 0
                    && self::$frameworkPrefix === SymfonyIntegration::NAME
                    && !\DDTrace\are_endpoints_collected())
                {
                    /** @var ContainerInterface $container */
                    $container = self::$kernel->getContainer();
                    $endpoints = EndpointCatalog::generate($container);
                    foreach ($endpoints as $endpoint) {
                        \DDTrace\add_endpoint($endpoint['path'], 'http.request', $endpoint['resourceName'], $endpoint['method']);
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
        $exceptionHandlingTracer = static function(SpanData $span, $args, $retval) {
            $span->name = $span->resource = 'symfony.kernel.handleException';
            $span->service = \ddtrace_config_app_name(self::$frameworkPrefix);
            $span->meta[Tag::COMPONENT] = self::NAME;
            \DDTrace\root_span()->exception = $args[0];


            if (isset($retval) && \method_exists($retval, 'getStatusCode') && $retval->getStatusCode() < 500) {
                // It means that the exception event associated with the exception had a response, which certainly
                // means that the exception was handled.
                \DDTrace\root_span()->meta['error.ignored'] = 1;
            }
        };
        // Symfony 4.3-
        \DDTrace\trace_method('Symfony\Component\HttpKernel\HttpKernel', 'handleException', $exceptionHandlingTracer);
        // Symfony 4.4+
        \DDTrace\trace_method('Symfony\Component\HttpKernel\HttpKernel', 'handleThrowable', $exceptionHandlingTracer);

        // Tracing templating engines
        $traceRender = function(SpanData $span, $args) {
            $span->name = 'symfony.templating.render';
            $span->service = \ddtrace_config_app_name(SymfonyIntegration::$frameworkPrefix);
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

        /* Silence ExecIntegration spans to stty. These are going to fail intentionally,
         * and always executed within symfony requests. This is pure noise which we hereby silence.
         */
        foreach (['Symfony\Component\Console\Terminal::hasSttyAvailable', 'Symfony\Component\Console\Helper\QuestionHelper::isInteractiveInput'] as $method) {
            \DDTrace\install_hook($method, static function(HookData $hook) {
                $hook->data = false;
                \DDTrace\active_stack()->spanCreationObservers[] = static function(SpanData $span) use ($hook) {
                    if ($hook->data) {
                        return false;
                    }
                    $span->onClose[] = static function(SpanData $span) {
                        \DDTrace\try_drop_span($span);
                    };
                };
            }, static function(HookData $hook) {
                $hook->data = true;
            });
        }
    }

    /**
     * @param \Symfony\Component\EventDispatcher\Event $event
     * @param string $eventName
     * @param SpanData $requestSpan
     */
    public static function injectActionInfo($event, $eventName, SpanData $requestSpan)
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
