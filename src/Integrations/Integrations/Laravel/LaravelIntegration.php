<?php

namespace DDTrace\Integrations\Laravel;

use DDTrace\Integrations\Lumen\LumenIntegration;
use DDTrace\RootSpanData;
use DDTrace\SpanData;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;
use DDTrace\Type;

/**
 * The base Laravel integration which delegates loading to the appropriate integration version.
 */
class LaravelIntegration extends Integration
{
    const NAME = 'laravel';

    const UNNAMED_ROUTE = 'unnamed_route';

    /**
     * @var string
     */
    public $serviceName;

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

    public function isArtisanQueueCommand()
    {
        $artisanCommand = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : '';

        return !empty($artisanCommand)
            && in_array($artisanCommand, [
                'horizon:work',
                'queue:work',
                'horizon',
                'horizon:supervisor',
            ]);
    }

    public static function handleOrphan(SpanData $span)
    {
        if (dd_trace_env_config("DD_TRACE_REMOVE_AUTOINSTRUMENTATION_ORPHANS")
            && (
                \DDTrace\get_priority_sampling() == DD_TRACE_PRIORITY_SAMPLING_AUTO_KEEP
                || \DDTrace\get_priority_sampling() == DD_TRACE_PRIORITY_SAMPLING_USER_KEEP
            ) && $span instanceof RootSpanData && empty($span->parentId)
        ) {
            \DDTrace\set_priority_sampling(DD_TRACE_PRIORITY_SAMPLING_AUTO_REJECT);
        }
    }

    /**
     * @return int
     */
    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return Integration::NOT_LOADED;
        }

        $integration = $this;

        if (dd_trace_env_config("DD_TRACE_REMOVE_ROOT_SPAN_LARAVEL_QUEUE") && $this->isArtisanQueueCommand()) {
            ini_set("datadog.trace.auto_flush_enabled", 1);
            ini_set("datadog.trace.generate_root_span", 0);
        }


        \DDTrace\trace_method(
            'Illuminate\Foundation\Application',
            'handle',
            function (SpanData $span, $args, $response) use ($integration) {
                $span->name = 'laravel.application.handle';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                $span->resource = 'Illuminate\Foundation\Application@handle';
                $span->meta[Tag::COMPONENT] = LaravelIntegration::NAME;

                $rootSpan = \DDTrace\root_span();

                // Overwriting the default web integration
                $rootSpan->name = 'laravel.request';
                $integration->addTraceAnalyticsIfEnabled($rootSpan);
                if (\method_exists($response, 'getStatusCode')) {
                    $rootSpan->meta[Tag::HTTP_STATUS_CODE] = $response->getStatusCode();
                }
                $rootSpan->service = $integration->getServiceName();
                $rootSpan->meta[Tag::SPAN_KIND] = 'server';
                $rootSpan->meta[Tag::COMPONENT] = LaravelIntegration::NAME;
            }
        );

        \DDTrace\hook_method(
            'Illuminate\Contracts\Foundation\Application',
            'bootstrapWith',
            function ($app) use ($integration) {
                $integration->serviceName = ddtrace_config_app_name();
                if (empty($integration->serviceName)) {
                    $app->make('Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables')->bootstrap($app);
                    $configPath = realpath($app->configPath());
                    if (file_exists($configPath . '/app.php')) {
                        $config = require $configPath . '/app.php';
                        $integration->serviceName = $config['name'];
                    }
                }
            }
        );

        \DDTrace\hook_method(
            'Illuminate\Routing\Router',
            'findRoute',
            null,
            function ($This, $scope, $args, $route) use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan === null) {
                    return;
                }

                if (!isset($route)) {
                    return;
                }

                /** @var \Illuminate\Http\Request $request */
                list($request) = $args;

                // Overwriting the default web integration
                $integration->addTraceAnalyticsIfEnabled($rootSpan);
                $routeName = LaravelIntegration::normalizeRouteName($route->getName());

                if (PHP_VERSION_ID < 70000 || dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")) {
                    $rootSpan->resource = $route->getActionName() . ' ' . $routeName;
                }

                $rootSpan->meta['laravel.route.name'] = $routeName;
                $rootSpan->meta['laravel.route.action'] = $route->getActionName();

                if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                    $rootSpan->meta[Tag::HTTP_URL] = \DDTrace\Util\Normalizer::urlSanitize($request->fullUrl());
                }
                if (\method_exists($route, 'uri')) {
                    $rootSpan->meta[Tag::HTTP_ROUTE] = $route->uri();
                }
                $rootSpan->meta[Tag::HTTP_METHOD] = $request->method();
                $rootSpan->meta[Tag::SPAN_KIND] = 'server';
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Routing\Route',
            'run',
            function (SpanData $span) use ($integration) {
                $span->name = 'laravel.action';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                $span->resource = $this->uri;
                $span->meta[Tag::COMPONENT] = LaravelIntegration::NAME;
            }
        );

        \DDTrace\hook_method(
            'Illuminate\Http\Response',
            'send',
            function ($This, $scope, $args) use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan === null) {
                    return;
                }

                $ignoreError = isset($rootSpan->meta['error.ignored']) && $rootSpan->meta['error.ignored'];
                if (isset($This->exception) && $This->getStatusCode() >= 500 && !$ignoreError) {
                    $integration->setError($rootSpan, $This->exception);
                }
            }
        );

        // used by Laravel < 5.8
        \DDTrace\trace_method(
            'Illuminate\Events\Dispatcher',
            'fire',
            [
                'prehook' => function (SpanData $span, $args) use ($integration) {
                    LaravelIntegration::handleOrphan($span);

                    $span->name = 'laravel.event.handle';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $integration->getServiceName();
                    $span->resource = $args[0];
                    $span->meta[Tag::COMPONENT] = LaravelIntegration::NAME;

                    //New user created, assume sign up
                    if ($span->resource == 'eloquent.created: User') {
                        $authClass = 'User';
                        if (
                            !function_exists('\datadog\appsec\track_user_signup_event') ||
                            !isset($args[1]) ||
                            !$args[1] ||
                            !($args[1] instanceof $authClass)
                        ) {
                            return;
                        }
                        $id = null;
                        if (isset($args[1]['id'])) {
                            $id = $args[1]['id'];
                        }
                        \datadog\appsec\track_user_signup_event($id, [], true);
                    }
                },
                'recurse' => true,
            ]
        );

        // used by Laravel >= 5.8
        // More details: https://laravel.com/docs/5.8/upgrade#events
        \DDTrace\trace_method(
            'Illuminate\Events\Dispatcher',
            'dispatch',
            [
                'prehook' => function (SpanData $span, $args) use ($integration) {
                    LaravelIntegration::handleOrphan($span);

                    $span->name = 'laravel.event.handle';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $integration->getServiceName();
                    $span->resource = is_object($args[0]) ? get_class($args[0]) : $args[0];
                    $span->meta[Tag::COMPONENT] = LaravelIntegration::NAME;
                },
                'recurse' => true,
            ]
        );

        \DDTrace\trace_method('Illuminate\View\View', 'render', function (SpanData $span) use ($integration) {
            $span->name = 'laravel.view.render';
            $span->type = Type::WEB_SERVLET;
            $span->service = $integration->getServiceName();
            $span->resource = $this->view;
            $span->meta[Tag::COMPONENT] = LaravelIntegration::NAME;
        });

        \DDTrace\trace_method(
            'Illuminate\View\Engines\CompilerEngine',
            'get',
            function (SpanData $span, $args) use ($integration) {
                $rootSpan = \DDTrace\root_span();

                // This is used by both laravel and lumen. For consistency we rename it for lumen traces as otherwise
                // users would see a span changing name as they upgrade to the new version.
                $span->name = $integration->isLumen($rootSpan) ? 'lumen.view' : 'laravel.view';
                $span->meta[Tag::COMPONENT] = $span->name === 'laravel.view'
                    ? LaravelIntegration::NAME
                    : LumenIntegration::NAME;
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                if (isset($args[0]) && \is_string($args[0])) {
                    $span->resource = $args[0];
                }
            }
        );

        \DDTrace\trace_method(
            'Illuminate\Foundation\ProviderRepository',
            'load',
            function (SpanData $span) use ($integration) {
                $serviceName = $integration->getServiceName();

                $span->name = 'laravel.provider.load';
                $span->type = Type::WEB_SERVLET;
                $span->service = $serviceName;
                $span->resource = 'Illuminate\Foundation\ProviderRepository::load';
                $span->meta[Tag::COMPONENT] = LaravelIntegration::NAME;

                LaravelIntegration::handleOrphan($span);

                $rootSpan = \DDTrace\root_span();
                $rootSpan->name = 'laravel.request';
                $rootSpan->service = $serviceName;
                $rootSpan->meta[Tag::COMPONENT] = LaravelIntegration::NAME;
            }
        );

        \DDTrace\hook_method(
            'Illuminate\Console\Application',
            '__construct',
            function () use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan === null) {
                    return;
                }

                $rootSpan->name = 'laravel.artisan';
                $rootSpan->resource = !empty($_SERVER['argv'][1]) ? 'artisan ' . $_SERVER['argv'][1] : 'artisan';
                unset($rootSpan->meta[Tag::SPAN_KIND]);
                $rootSpan->meta[Tag::COMPONENT] = LaravelIntegration::NAME;
            }
        );

        // renderException is since Symfony 4.4, use "renderThrowable()" instead
        // Used by Laravel < v7.0
        \DDTrace\hook_method(
            'Symfony\Component\Console\Application',
            'renderException',
            function ($This, $scope, $args) use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan !== null) {
                    $integration->setError($rootSpan, $args[0]);
                }
            }
        );

        // Used by Laravel > v7.0
        // More details: https://github.com/laravel/framework/commit/f81b6ed01fb60580ade8c7fb4386aff4cb4d7719
        \DDTrace\hook_method(
            'Symfony\Component\Console\Application',
            'renderThrowable',
            function ($This, $scope, $args) use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan !== null) {
                    $integration->setError($rootSpan, $args[0]);
                }
            }
        );

        // Used by Laravel >= 5.0
        \DDTrace\hook_method(
            'Illuminate\Contracts\Debug\ExceptionHandler',
            'report',
            function ($exceptionHandler, $scope, $args) use ($integration) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan === null) {
                    return;
                }

                if ($args[0] && $exceptionHandler->shouldReport($args[0])) {
                    $integration->setError($rootSpan, $args[0]);
                    $rootSpan->meta['error.ignored'] = 0;
                } elseif ($args[0] && !$exceptionHandler->shouldReport($args[0])) {
                    $rootSpan->meta['error.ignored'] = 1;
                }
            }
        );

        // Used by Laravel >= 5.0
        \DDTrace\hook_method(
            'Illuminate\Auth\SessionGuard',
            'attempt',
            null,
            function ($This, $scope, $args, $loginSuccess) use ($integration) {
                if ($loginSuccess || !function_exists('\datadog\appsec\track_user_login_failure_event')) {
                    return;
                }
                \datadog\appsec\track_user_login_failure_event(null, false, [], true);
            }
        );

        // Used by Laravel >= 5.0
        \DDTrace\hook_method(
            'Illuminate\Auth\SessionGuard',
            'setUser',
            function ($This, $scope, $args) use ($integration) {
                $authClass = 'Illuminate\Contracts\Auth\Authenticatable';
                if (
                    !function_exists('\datadog\appsec\track_user_login_success_event') ||
                    !isset($args[0]) ||
                    !$args[0] ||
                    !($args[0] instanceof $authClass)
                ) {
                    return;
                }
                $metadata = [];
                if (isset($args[0]['name'])) {
                    $metadata['name'] = $args[0]['name'];
                }
                if (isset($args[0]['email'])) {
                    $metadata['email'] = $args[0]['email'];
                }
                \datadog\appsec\track_user_login_success_event(
                    \method_exists($args[0], 'getAuthIdentifier') ? $args[0]->getAuthIdentifier() : '',
                    $metadata,
                    true
                );
            }
        );

        // Used by Laravel < 5.0
        \DDTrace\hook_method(
            'Illuminate\Auth\Guard',
            'setUser',
            function ($This, $scope, $args) use ($integration) {
                $authClass = 'Illuminate\Auth\UserInterface';
                if (
                    !function_exists('\datadog\appsec\track_user_login_success_event') ||
                    !isset($args[0]) ||
                    !$args[0] ||
                    !($args[0] instanceof $authClass)
                ) {
                    return;
                }

                $metadata = [];
                if (isset($args[0]['name'])) {
                    $metadata['name'] = $args[0]['name'];
                }
                if (isset($args[0]['email'])) {
                    $metadata['email'] = $args[0]['email'];
                }

                \datadog\appsec\track_user_login_success_event(
                    \method_exists($args[0], 'getAuthIdentifier') ? $args[0]->getAuthIdentifier() : '',
                    $metadata,
                    true
                );
            }
        );

        // Used by Laravel < 5.0
        \DDTrace\hook_method(
            'Illuminate\Auth\Guard',
            'attempt',
            null,
            function ($This, $scope, $args, $loginSuccess) use ($integration) {
                if ($loginSuccess || !function_exists('\datadog\appsec\track_user_login_failure_event')) {
                    return;
                }
                \datadog\appsec\track_user_login_failure_event(null, false, [], true);
            }
        );

        \DDTrace\hook_method(
            'Illuminate\Auth\Events\Registered',
            '__construct',
            null,
            function ($This, $scope, $args) use ($integration) {
                $authClass = 'Illuminate\Contracts\Auth\Authenticatable';
                if (
                    !function_exists('\datadog\appsec\track_user_signup_event') ||
                    !isset($args[0]) ||
                    !$args[0] ||
                    !($args[0] instanceof $authClass)
                ) {
                    return;
                }
                \datadog\appsec\track_user_signup_event(
                    \method_exists($args[0], 'getAuthIdentifier') ? $args[0]->getAuthIdentifier() : '',
                    [],
                    true
                );
            }
        );

        return Integration::LOADED;
    }

    public function getServiceName()
    {
        if (!empty($this->serviceName)) {
            return $this->serviceName;
        }
        $this->serviceName = \ddtrace_config_app_name();
        if (empty($this->serviceName) && is_callable('config')) {
            $this->serviceName = config('app.name');
        }
        return $this->serviceName ?: 'laravel';
    }

    /**
     * Tells whether a span is a lumen request.
     *
     * @param SpanData $rootSpan
     * @return bool
     */
    public function isLumen(SpanData $rootSpan)
    {
        return $rootSpan->name === 'lumen.request';
    }

    /**
     * @param mixed $routeName
     * @return string
     */
    public static function normalizeRouteName($routeName)
    {
        if (!\is_string($routeName)) {
            return LaravelIntegration::UNNAMED_ROUTE;
        }

        $routeName = \trim($routeName);
        if ($routeName === '') {
            return LaravelIntegration::UNNAMED_ROUTE;
        }

        // Starting with PHP 7, unnamed routes have been given a randomly generated name that we need to
        // normalize:
        // https://github.com/laravel/framework/blob/7.x/src/Illuminate/Routing/AbstractRouteCollection.php#L227
        //
        // It can also be prefixed with domain name when caching is specified as Route::domain()->group(...);
        if (\strpos($routeName, 'generated::') !== false) {
            return LaravelIntegration::UNNAMED_ROUTE;
        }

        return $routeName;
    }
}
