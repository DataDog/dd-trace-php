<?php

namespace DDTrace\Integrations\Laminas;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Logs\LogsIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use DDTrace\Util\ObjectKVStore;
use Laminas\ApiTools\ApiProblem\ApiProblemResponse;
use Laminas\EventManager\EventInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\RouteMatch;
use Laminas\Stdlib\RequestInterface;
use Laminas\View\Model\ModelInterface;

use function DDTrace\active_span;
use function DDTrace\hook_method;
use function DDTrace\install_hook;
use function DDTrace\logs_correlation_trace_id;
use function DDTrace\remove_hook;
use function DDTrace\root_span;
use function DDTrace\trace_method;

class LaminasIntegration extends Integration
{
    const NAME = 'laminas';

    // \Laminas\Mvc\Application is not necessarily loaded (e.g., Laminas Log), hence the raw strings
    static $ERROR_TYPES = [
        'error-controller-cannot-dispatch',
        'error-controller-not-found',
        'error-controller-invalid',
        'error-exception',
        'error-router-no-match',
        'error-middleware-cannot-dispatch'
    ];

    static $EVENT_TYPES = [
        'create',
        'delete',
        'deleteList',
        'fetch',
        'fetchAll',
        'patch',
        'patchList',
        'replaceList',
        'update'
    ];

    const MVC_EVENTS = [
        'bootstrap',
        'dispatch',
        'dispatch.error',
        'finish',
        'render',
        'render.error',
        'route'
    ];

    const EVENTS = [
        'bootstrap',
        'dispatch',
        'dispatch.error',
        'finish',
        'render',
        'render.error',
        'route',

        'mergeConfig',
        'loadModules',
        //'loadModule.resolve',
        //'loadModule',
        'loadModules.post',

        'renderer',
        'renderer.post',
        'response',

        'sendResponse'
    ];

    public static function init(): int
    {
        if (self::shouldLoad(LogsIntegration::NAME)) {
            // Logs Correlation
            install_hook(
                "Laminas\Log\Logger::log",
                LogsIntegration::getHookFn('log', 1, 2, 0)
            );

            install_hook(
                "Laminas\Log\Formatter\Json::format",
                null,
                static function (HookData $hook) {
                    $logArray = json_decode($hook->returned, true);

                    $traceId = logs_correlation_trace_id();
                    $spanId = dd_trace_peek_span_id();

                    $modified = false;

                    if (isset($logArray['extra']['dd.trace_id'])) {
                        $logArray['extra']['dd.trace_id'] = $traceId;
                        $modified = true;
                    }

                    if (isset($logArray['extra']['dd.span_id'])) {
                        $logArray['extra']['dd.span_id'] = $spanId;
                        $modified = true;
                    }

                    if ($modified) {
                        // Doesn't use JSON_NUMERIC_CHECK because it would convert trace identifiers strings to numbers
                        $fixedJson = @json_encode(
                            $logArray,
                            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
                        );

                        $hook->overrideReturnValue($fixedJson);
                    }
                }
            );
        }

        // @see https://github.com/laminas/laminas-eventmanager/blob/3.11.x/src/EventManagerInterface.php#L94
        hook_method(
            'Laminas\EventManager\EventManagerInterface',
            'attach',
            null,
            static function ($This, $score, $args) {
                $eventName = $args[0];
                if (!is_string($eventName)) {
                    return; // If such a case happen, an exception will be thrown by the framework
                }

                // Only instrument Mvc events triggered by eventmanager, as the other events would add too much noise
                if (!in_array($eventName, self::MVC_EVENTS)) {
                    return;
                }

                $listener = $args[1];
                if (!is_array($listener) || !is_object($listener[0]) || !is_string($listener[1])) {
                    return;
                }
                $className = get_class($listener[0]);
                $methodName = $listener[1];
                trace_method(
                    $className,
                    $methodName,
                    static function (SpanData $span) use ($className, $methodName) {
                        $span->name = 'laminas.mvcEventListener';
                        $span->resource = $className . '@' . $methodName;
                        $span->type = Type::WEB_SERVLET;
                        $span->service = \ddtrace_config_app_name('laminas');
                        $span->meta[Tag::COMPONENT] = 'laminas';
                    }
                );
            }
        );



        // Overall application flow
        trace_method(
            'Laminas\Mvc\Application',
            'init',
            static function (SpanData $span) {
                $span->name = 'laminas.application.init';
                $span->resource = 'laminas.application.init';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('laminas');
                $span->meta[Tag::COMPONENT] = 'laminas';

                $rootSpan = root_span();
                $rootSpan->name = 'laminas.request';
                $rootSpan->service = \ddtrace_config_app_name('laminas');
                $rootSpan->meta[Tag::SPAN_KIND] = 'server';
                $rootSpan->meta[Tag::COMPONENT] = self::NAME;
            }
        );

        trace_method(
            'Laminas\Mvc\Application',
            'bootstrap',
            static function (SpanData $span) {
                $span->name = 'laminas.application.bootstrap';
                $span->resource = 'laminas.application.bootstrap';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('laminas');
                $span->meta[Tag::COMPONENT] = 'laminas';
            }
        );

        trace_method(
            'Laminas\EventManager\EventManager',
            'triggerListeners',
            [
                'prehook' => static function (SpanData $span, $args) {
                    /** @var EventInterface $event */
                    $event = $args[0];
                    $eventName = $event->getName();

                    if (!in_array($eventName, self::EVENTS)) {
                        return;  // In other words, skips 'loadModule' and 'loadModule.resolve', which are too noisy
                    }

                    $span->name = "laminas.event.$eventName";
                    $span->service = \ddtrace_config_app_name('laminas');
                    $span->meta[Tag::COMPONENT] = 'laminas';
                }
            ]
        );

        trace_method(
            'Laminas\Mvc\Application',
            'run',
            [
                'prehook' => static function (SpanData $span) {
                    $service = \ddtrace_config_app_name('laminas');
                    $span->name = 'laminas.application.run';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $service;
                    $span->meta[Tag::COMPONENT] = 'laminas';
                }
            ]
        );

        // MvcEvent::EVENT_ROUTE
        trace_method(
            'Laminas\Router\RouteInterface',
            'match',
            function (SpanData $span, $args, $retval) {
                $span->name = 'laminas.route.match';
                $span->resource = \get_class($this) . '@match';
                $span->meta[Tag::COMPONENT] = 'laminas';

                /** @var RequestInterface $request */
                $request = $args[0];

                $rootSpan = root_span();
                $rootSpan->meta[Tag::HTTP_METHOD] = $request->getMethod();
                $rootSpan->meta[Tag::HTTP_VERSION] = $request->getVersion();
                $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize($request->getUriString());

                $routeMatch = $retval;
                if (is_null($routeMatch)) {
                    return;
                }

                /** @var RouteMatch $routeMatch */

                $routeName = $routeMatch->getMatchedRouteName();
                $action = $routeMatch->getParam('action');
                $controller = $routeMatch->getParam('controller');

                if (method_exists($controller, $action . 'Action')) {
                    trace_method(
                        $controller,
                        $action . "Action",
                        static function (SpanData $span) use ($controller, $action) {
                            $span->name = 'laminas.controller.action';
                            $span->resource = "$controller@{$action}Action";
                            $span->meta[Tag::COMPONENT] = 'laminas';
                        }
                    );
                }

                if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")) {
                    $rootSpan->resource = "$controller@$action $routeName";
                }
                $rootSpan->meta['laminas.route.name'] = $routeName;
                $rootSpan->meta['laminas.route.action'] = "$controller@$action";

                // Push path params to appsec
                if (function_exists('\datadog\appsec\push_addresses')) {
                    $params = $routeMatch->getParams();
                    // Filter out the framework-specific params (controller, action)
                    $pathParams = array_diff_key($params, array_flip(['controller', 'action']));
                    if (count($pathParams) > 0) {
                        \datadog\appsec\push_addresses(["server.request.path_params" => $pathParams]);
                    }
                }
            }
        );

        if (\class_exists('Laminas\\Mvc\\RouteListener')) {
            hook_method(
                'Laminas\Mvc\RouteListener',
                'onRoute',
                null,
                static function ($This, $scope, $args) {
                    if (!isset($args[0]) || !($args[0] instanceof MvcEvent)) {
                        return;
                    }
                    /** @var MvcEvent $event */
                    $event = $args[0];
                    if ($event->getRouteMatch() === null) {
                        return;
                    }
                    if (\DDTrace\are_endpoints_collected()) {
                        return;
                    }
                    $router = $event->getRouter();
                    if ($router === null || !($router instanceof \Laminas\Router\RouteStackInterface)) {
                        return;
                    }
                    LaminasIntegration::registerLaminasRouteEndpoints($router);
                }
            );
        }

        hook_method(
            'Laminas\Http\Response',
            'setStatusCode',
            static function ($This, $scope, $args) {
                $rootSpan = root_span();
                if ($rootSpan !== null) {
                    $statusCode = $args[0];
                    $rootSpan->meta[Tag::HTTP_STATUS_CODE] = "$statusCode";
                }
            }
        );

        // MvcEvent:EVENT_DISPATCH & MvcEvent::EVENT_DISPATCH_ERROR
        trace_method(
            'Laminas\Stdlib\DispatchableInterface',
            'dispatch',
            function (SpanData $span) {
                $span->name = 'laminas.controller.dispatch';
                $span->resource = \get_class($this);
                $span->meta[Tag::COMPONENT] = 'laminas';
            }
        );

        hook_method(
            'Laminas\Mvc\Controller\AbstractController',
            'onDispatch',
            null,
            static function ($This, $score, $args) {
                $rootSpan = root_span();
                if ($rootSpan === null) {
                    return false;
                }

                /** @var MvcEvent $event */
                $event = $args[0];

                $exception = $event->getParam('exception');
                if ($exception) {
                    $rootSpan->exception = $exception;
                }
            }
        );

        // MvcEvent::EVENT_RENDER & MvcEvent::EVENT_RENDER_ERROR
        trace_method(
            'Laminas\Mvc\Application',
            'completeRequest',
            static function (SpanData $span, $args) {
                $span->name = 'laminas.application.completeRequest';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;
                $span->meta[Tag::COMPONENT] = 'laminas';

                /** @var MvcEvent $event */
                $event = $args[0];

                $request = $event->getRequest();
                $method = $request->getMethod();

                $rootSpan = root_span();
                $rootSpan->meta[Tag::HTTP_METHOD] = $method;
                $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize($request->getUriString());
            }
        );

        trace_method(
            'Laminas\View\Renderer\RendererInterface',
            'render',
            [
                'prehook' => static function (SpanData $span, $args) {
                    $span->name = 'laminas.templating.render';
                    $span->service = \ddtrace_config_app_name('laminas');
                    $span->type = Type::WEB_SERVLET;
                    $span->meta[Tag::COMPONENT] = 'laminas';

                    $nameOrModel = $args[0];
                    if (is_string($nameOrModel)) {
                        $span->resource = $nameOrModel;
                    } elseif ($nameOrModel instanceof ModelInterface) {
                        $span->resource = $nameOrModel->getTemplate();
                    }
                },
                'recurse' => true
            ]
        );

        trace_method(
            'Laminas\View\View',
            'render',
            static function (SpanData $span) {
                $span->name = 'laminas.view.render';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;
                $span->meta[Tag::COMPONENT] = 'laminas';
            }
        );

        trace_method(
            'Laminas\View\Model\JsonModel',
            'serialize',
            function (SpanData $span) {
                $span->name = 'laminas.view.model.serialize';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->resource = \get_class($this);
                $span->meta[Tag::COMPONENT] = 'laminas';
            }
        );

        trace_method(
            'Laminas\Mvc\View\Http\DefaultRenderingStrategy',
            'render',
            function (SpanData $span, $args) {
                $span->name = 'laminas.view.http.renderer';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;
                $span->resource = \get_class($this) . '@render';
                $span->meta[Tag::COMPONENT] = 'laminas';

                /** @var MvcEvent $event */
                $event = $args[0];

                $exception = $event->getParam('exception');
                if ($exception) {
                    root_span()->exception = $exception;
                }
            }
        );

        trace_method(
            'Laminas\Mvc\View\Console\DefaultRenderingStrategy',
            'render',
            function (SpanData $span, $args) {
                $span->name = 'laminas.view.console.renderer';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;
                $span->resource = \get_class($this) . '@render';
                $span->meta[Tag::COMPONENT] = 'laminas';

                /** @var MvcEvent $event */
                $event = $args[0];

                $exception = $event->getParam('exception');
                if ($exception) {
                    root_span()->exception = $exception;
                }
            }
        );

        // Generic Error Handling
        trace_method(
            'Laminas\Mvc\MvcEvent',
            'setError',
            static function (SpanData $span, $args, $retval) {
                $span->name = 'laminas.mvcEvent.setError';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;
                $span->meta[Tag::COMPONENT] = 'laminas';

                /** @var MvcEvent $event */
                $event = $retval;

                /** @var string $errorType */
                $errorType = $args[0];
                if (isset($errorType, self::$ERROR_TYPES)) {
                    $span->resource = $errorType;
                }

                $exception = $event->getParam('exception');
                if ($exception) {
                    root_span()->exception = $exception;
                }
            }
        );

        // Misc.
        trace_method(
            'Laminas\Mvc\Controller\PluginManager',
            'get',
            static function (SpanData $span, $args) {
                $span->name = 'laminas.controller.pluginManager.get';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->resource = $args[0];
                $span->meta[Tag::COMPONENT] = 'laminas';
            }
        );

        trace_method(
            'Laminas\Mvc\Controller\AbstractController',
            'forward',
            static function (SpanData $span, $args) {
                $span->name = 'laminas.controller.forward';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->meta[Tag::COMPONENT] = 'laminas';

                $controllerName = $args[0];
                if (isset($args[1]) && isset($args[1]['action'])) {
                    $actionName = $args[1]['action'];
                    $span->resource = $controllerName . '@' . $actionName;
                } else {
                    $span->resource = $controllerName;
                }
            }
        );

        // REST
        hook_method(
            'Laminas\ApiTools\Rest\AbstractResourceListener',
            'dispatch',
            static function ($This, $scope, $args) {
                $rootSpan = root_span();
                if ($rootSpan === null) {
                    return false;
                }

                /** @var \Laminas\ApiTools\Rest\ResourceEvent $event */
                $event = $args[0];
                $eventName = $event->getName();
                $controller = $scope;
                $routeName = $event->getRouteMatch()->getMatchedRouteName();

                $rootSpan->resource = "$controller@$eventName $routeName";
                $rootSpan->meta['laminas.route.name'] = $routeName;
                $rootSpan->meta['laminas.route.action'] = $controller . '@' . $eventName;

                if (isset($eventName, self::$EVENT_TYPES)) {
                    install_hook(
                        "$controller::$eventName",
                        static function (HookData $hook) use ($controller, $eventName) {
                            $span = $hook->span();
                            $span->name = 'laminas.controller.action';
                            $span->resource = "$controller@$eventName";
                            $span->meta[Tag::COMPONENT] = 'laminas';
                            remove_hook($hook->id);
                        }
                    );
                }
            }
        );

        // ApiProblem
        install_hook(
            'Laminas\ApiTools\ApiProblem\ApiProblem::__construct',
            static function (HookData $hook) {
                $args = $hook->args;
                $detail = $args[1] ?? null;
                $activeSpan = active_span();
                if ($detail instanceof \Throwable || $detail instanceof \Exception) {
                    if ($activeSpan !== null && !isset($activeSpan->meta[Tag::ERROR_TYPE])) {
                        $activeSpan->exception = $detail;
                    }
                } elseif (is_string($detail)) {
                    // Removes the first two frames, which are the constructor and the hook
                    $stack = debug_backtrace();
                    array_shift($stack);
                    array_shift($stack);
                    $backtrace = LaminasIntegration::debugBacktraceToString($stack);

                    ObjectKVStore::put($hook->instance, 'backtrace', $backtrace);

                    if ($activeSpan !== null && !isset($activeSpan->meta[Tag::ERROR_TYPE])) {
                        $activeSpan->meta[Tag::ERROR_TYPE] = 'ApiProblem';
                        $activeSpan->meta[Tag::ERROR_MSG] = $detail;
                        $activeSpan->meta[Tag::ERROR_STACK] = $backtrace;
                    }
                } // There shouldn't be any other case, per the ApiProblem spec
            }
        );

        hook_method(
            'Laminas\ApiTools\ApiProblem\Listener\SendApiProblemResponseListener',
            'sendContent',
            null,
            static function ($This, $scope, $args) {
                $rootSpan = root_span();
                if ($rootSpan === null) {
                    return;
                }

                /** @var \Laminas\Mvc\ResponseSender\SendResponseEvent $e */
                $e = $args[0];
                $response = $e->getResponse();
                if ($response instanceof ApiProblemResponse) {
                    $apiProblem = $response->getApiProblem();
                    $detail = $apiProblem->detail; // __get
                    $status = $apiProblem->status; // __get
                    if ($status < 500) {
                        return; // Only set 5xx on the root span
                    }

                    if ($detail instanceof \Throwable || $detail instanceof \Exception) {
                        $rootSpan->exception = $detail;
                    } elseif (is_string($detail)) {
                        $title = $apiProblem->title; // __get
                        $rootSpan->meta[Tag::ERROR_TYPE] = $title ?: 'ApiProblem';
                        $rootSpan->meta[Tag::ERROR_MSG] = $detail;

                        $backtrace = ObjectKVStore::get($apiProblem, 'backtrace');
                        if ($backtrace !== null) {
                            $rootSpan->meta[Tag::ERROR_STACK] = $backtrace;
                        }
                    } // There shouldn't be any other case, per the ApiProblem spec
                }
            }
        );

        install_hook(
            'Laminas\Authentication\AuthenticationService::authenticate',
            null,
            static function (HookData $hook) {
                $result = $hook->returned;

                if (!$result instanceof \Laminas\Authentication\Result) {
                    return;
                }

                if ($result->isValid()) {
                    self::trackUserLoginSuccess($result, $hook->args[0] ?? null);
                    return;
                }

                if (!function_exists('\datadog\appsec\track_user_login_failure_event_automated')) {
                    return;
                }

                $code = $result->getCode();
                $adapter = $hook->args[0] ?? null;
                $userLogin = null;

                if ($adapter && method_exists($adapter, 'getIdentity')) {
                    $userLogin = $adapter->getIdentity();
                }

                $userExists = ($code === \Laminas\Authentication\Result::FAILURE_CREDENTIAL_INVALID);

                \datadog\appsec\track_user_login_failure_event_automated(
                    $userLogin,
                    $userLogin,
                    $userExists,
                    []
                );
            }
        );

        hook_method(
            'Laminas\Authentication\AuthenticationService',
            'getIdentity',
            null,
            static function ($This, $scope, $args, $identity) {
                if ($identity === null || $identity === false) {
                    return;
                }
                if (!function_exists('\datadog\appsec\track_authenticated_user_event_automated')) {
                    return;
                }

                $userId = self::getUserId($identity);
                if ($userId === '') {
                    return;
                }

                \datadog\appsec\track_authenticated_user_event_automated($userId);
            }
        );

        return Integration::LOADED;
    }

    private static function trackUserLoginSuccess(
        \Laminas\Authentication\Result $result,
        $adapter
    ) {
        if (!function_exists('\datadog\appsec\track_user_login_success_event_automated')) {
            return;
        }

        $identity = $result->getIdentity();
        if ($adapter !== null && method_exists($adapter, 'getResultRowObject')) {
            $row = $adapter->getResultRowObject();
            if (is_object($row)) {
                $identity = $row;
            }
        }

        if ($identity === null || $identity === false || $identity === '') {
            return;
        }

        $userId = self::getUserId($identity);
        if ($userId === '') {
            return;
        }

        $userLogin = self::getUserLogin($identity);
        $metadata = self::getUserMetadata($identity);

        \datadog\appsec\track_user_login_success_event_automated(
            $userLogin,
            $userId,
            $metadata
        );
    }

    private static function getUserId($identity)
    {
        if (is_string($identity) || is_int($identity)) {
            return (string)$identity;
        }

        if (is_array($identity)) {
            if (isset($identity['id'])) {
                return (string)$identity['id'];
            }
            if (isset($identity['user_id'])) {
                return (string)$identity['user_id'];
            }
            if (isset($identity['username'])) {
                return $identity['username'];
            }
            if (isset($identity['email'])) {
                return $identity['email'];
            }
        }

        if (is_object($identity)) {
            if (isset($identity->id)) {
                return (string)$identity->id;
            }
            if (isset($identity->user_id)) {
                return (string)$identity->user_id;
            }
            if (isset($identity->userId)) {
                return (string)$identity->userId;
            }

            if (method_exists($identity, 'getId')) {
                return (string)$identity->getId();
            }
            if (method_exists($identity, 'getUserId')) {
                return (string)$identity->getUserId();
            }
            if (method_exists($identity, 'getUsername')) {
                return $identity->getUsername();
            }
            if (method_exists($identity, 'getEmail')) {
                return $identity->getEmail();
            }

            if ($identity instanceof \ArrayAccess) {
                if (isset($identity['id'])) {
                    return (string)$identity['id'];
                }
                if (isset($identity['user_id'])) {
                    return (string)$identity['user_id'];
                }
                if (isset($identity['username'])) {
                    return $identity['username'];
                }
                if (isset($identity['email'])) {
                    return $identity['email'];
                }
            }
        }

        return '';
    }

    private static function getUserLogin($identity)
    {
        if (is_string($identity)) {
            return $identity;
        }

        if (is_array($identity)) {
            if (isset($identity['email'])) {
                return $identity['email'];
            }
            if (isset($identity['username'])) {
                return $identity['username'];
            }
        }

        if (is_object($identity)) {
            if (isset($identity->email)) {
                return $identity->email;
            }
            if (isset($identity->username)) {
                return $identity->username;
            }

            if (method_exists($identity, 'getEmail')) {
                return $identity->getEmail();
            }
            if (method_exists($identity, 'getUsername')) {
                return $identity->getUsername();
            }

            if ($identity instanceof \ArrayAccess) {
                if (isset($identity['email'])) {
                    return $identity['email'];
                }
                if (isset($identity['username'])) {
                    return $identity['username'];
                }
            }
        }

        return null;
    }

    private static function getUserMetadata($identity)
    {
        $metadata = [];

        if (is_array($identity)) {
            if (isset($identity['name'])) {
                $metadata['name'] = $identity['name'];
            }
            if (isset($identity['email'])) {
                $metadata['email'] = $identity['email'];
            }
            return $metadata;
        }

        if (is_object($identity)) {
            if (isset($identity->name)) {
                $metadata['name'] = $identity->name;
            }
            if (isset($identity->email)) {
                $metadata['email'] = $identity->email;
            }

            if (method_exists($identity, 'getName')) {
                $metadata['name'] = $identity->getName();
            }
            if (method_exists($identity, 'getEmail') && !isset($metadata['email'])) {
                $metadata['email'] = $identity->getEmail();
            }

            if ($identity instanceof \ArrayAccess) {
                if (isset($identity['name']) && !isset($metadata['name'])) {
                    $metadata['name'] = $identity['name'];
                }
                if (isset($identity['email']) && !isset($metadata['email'])) {
                    $metadata['email'] = $identity['email'];
                }
            }
        }

        return $metadata;
    }

    public static function httpRouteTemplateFromMatchedRoute($matchedRoute, $routeMatch = null)
    {
        if (is_object($matchedRoute)) {
            if (method_exists($matchedRoute, 'getSpec')) {
                $routeSpec = $matchedRoute->getSpec();
                if (is_string($routeSpec) && $routeSpec !== '') {
                    return $routeSpec;
                }
            }
            
            if (
                method_exists($matchedRoute, 'getRoute')
                && !($matchedRoute instanceof \Laminas\Router\RouteStackInterface)
            ) {
                $routeSpec = $matchedRoute->getRoute();
                if (is_string($routeSpec) && $routeSpec !== '') {
                    return $routeSpec;
                }
            }
        }

        if ($matchedRoute instanceof \Laminas\Router\Http\Literal) {
            $rp = new \ReflectionProperty($matchedRoute, 'route');
            $rp->setAccessible(true);

            return (string) $rp->getValue($matchedRoute);
        }

        if ($matchedRoute instanceof \Laminas\Router\Http\Segment) {
            $rp = new \ReflectionProperty($matchedRoute, 'parts');
            $rp->setAccessible(true);
            $parts = $rp->getValue($matchedRoute);

            return \is_array($parts) ? self::laminasSegmentPartsToRouteTemplate($parts) : null;
        }

        if (
            $matchedRoute instanceof \Laminas\Router\Http\TreeRouteStack
            && $routeMatch instanceof RouteMatch
        ) {
            if (method_exists($routeMatch, 'getMatchedRoute')) {
                $getMatchedRoute = [$routeMatch, 'getMatchedRoute'];
                $nestedMatchedRoute = call_user_func($getMatchedRoute);
                $nestedTemplate = self::httpRouteTemplateFromMatchedRoute($nestedMatchedRoute, $routeMatch);
                if ($nestedTemplate !== null && $nestedTemplate !== '') {
                    return $nestedTemplate;
                }
            }

            $matchedName = $routeMatch->getMatchedRouteName();
            if ($matchedName === null || $matchedName === '') {
                return null;
            }

            return self::httpRouteTemplateFromNamedRouteStack($matchedRoute, (string) $matchedName);
        }

        return null;
    }
    
    private static function laminasSegmentPartsToRouteTemplate(array $parts): string
    {
        $buf = '';
        foreach ($parts as $part) {
            if (!\is_array($part) || !isset($part[0])) {
                continue;
            }
            switch ($part[0]) {
                case 'literal':
                    $buf .= $part[1] ?? '';
                    break;
                case 'parameter':
                    $buf .= ':';
                    $buf .= $part[1] ?? '';
                    if (isset($part[2]) && $part[2] !== null && $part[2] !== '') {
                        $buf .= '{' . $part[2] . '}';
                    }
                    break;
                case 'optional':
                    $buf .= '[' . self::laminasSegmentPartsToRouteTemplate($part[1] ?? []) . ']';
                    break;
                case 'translated-literal':
                    $buf .= '{' . ($part[1] ?? '') . '}';
                    break;
            }
        }

        return $buf;
    }

    public static function registerLaminasRouteEndpoints($rootRouter): void
    {
        if (\DDTrace\are_endpoints_collected()) {
            return;
        }
        if (!($rootRouter instanceof \Laminas\Router\RouteStackInterface)) {
            return;
        }
        $endpoints = self::collectLaminasRouteEndpointRows($rootRouter, $rootRouter, '');
        foreach ($endpoints as $row) {
            \DDTrace\add_endpoint($row['path'], 'http.request', $row['resourceName'], $row['method']);
        }
    }

    private static function collectLaminasRouteEndpointRows($rootRouter, $currentStack, string $namePrefix): array
    {
        $rows = [];
        self::walkRouteStackCollectEndpointRows($rootRouter, $currentStack, $namePrefix, $rows);

        return self::dedupeLaminasEndpointRows($rows);
    }

    private static function dedupeLaminasEndpointRows(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $key = $row['method'] . "\0" . $row['path'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $row;
        }

        return $out;
    }

    private static function walkRouteStackCollectEndpointRows(
        $rootRouter,
        $currentStack,
        string $namePrefix,
        array &$rows
    ): void {
        foreach ($currentStack->getRoutes() as $name => $route) {
            $qualifiedName = $namePrefix === '' ? (string) $name : $namePrefix . '/' . $name;
            $path = self::httpRouteTemplateFromNamedRouteStack($rootRouter, $qualifiedName);
            if ($path !== null && $path !== '') {
                $method = 'GET';
                $rows[] = [
                    'path' => $path,
                    'method' => $method,
                    'resourceName' => $method . ' ' . $path,
                ];
            }
            if ($route instanceof \Laminas\Router\Http\Part) {
                self::walkRouteStackCollectEndpointRows($rootRouter, $route, $qualifiedName, $rows);
            }
        }
    }

    public static function httpRouteTemplateFromNamedRouteStack($stack, string $matchedName): ?string
    {
        $segments = \explode('/', $matchedName, 2);
        $route = $stack->getRoute($segments[0]);
        if ($route === null) {
            return null;
        }

        $hasChild = isset($segments[1]);

        if ($route instanceof \Laminas\Router\Http\Part) {
            $base = self::partRouteBaseTemplate($route);
            $base = $base ?? '';
            if (!$hasChild) {
                return $base !== '' ? $base : null;
            }
            $child = self::httpRouteTemplateFromNamedRouteStack($route, $segments[1]);
            if ($child === null) {
                return $base !== '' ? $base : null;
            }

            return $base . $child;
        }

        if ($route instanceof \Laminas\Router\Http\TreeRouteStack && $hasChild) {
            return self::httpRouteTemplateFromNamedRouteStack($route, $segments[1]);
        }

        if ($hasChild) {
            return self::httpRouteTemplateFromMatchedRoute($route, null);
        }

        return self::httpRouteTemplateFromMatchedRoute($route, null);
    }

    public static function partRouteBaseTemplate(\Laminas\Router\Http\Part $part): ?string
    {
        $rp = new \ReflectionProperty($part, 'route');
        $rp->setAccessible(true);
        $baseRoute = $rp->getValue($part);

        return self::httpRouteTemplateFromMatchedRoute($baseRoute, null);
    }

    public static function debugBacktraceToString(array $backtrace)
    {
        // (methods) #<frame index> <file>(line): <class><type><function>()\n
        // (functions) #<frame index> <file>(line): <function>()\n
        $result = '';
        foreach ($backtrace as $idx => $frame) {
            $result .= sprintf(
                "#%d %s(%d): ",
                $idx,
                $frame['file'] ?? '<unknown file>',
                $frame['line'] ?? '?'
            );
            if (isset($frame['class'])) {
                $result .= $frame['class'] . $frame['type'];
            }
            $result .= $frame['function'] . "()\n"; // Args aren't shown
        }
        return $result;
    }
}
