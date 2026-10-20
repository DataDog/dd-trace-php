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

use ReflectionProperty;

class LaminasIntegration extends Integration
{
    const NAME = 'laminas';

    /**
     * What each Literal, Wildcard, Segment and Regex match() that succeeded
     * matched: [route, path, offset, length]. Each list is attached to the
     * RouteMatch the match() returned, and RouteMatch::merge() appends the
     * merged match's list, as Part and Chain combine matches only that way.
     * The RouteMatch the router returns thus lists the routes Laminas
     * matched it with, in path order; matches from attempts that failed are
     * discarded with their RouteMatch. Null outside the MVC routing pass the
     * recording hooks are installed for.
     *
     * @var \SplObjectStorage|null RouteMatch => list of records
     */
    private static $routeMatchRecords = null;

    /** @var int[] ids of the recording hooks, installed for the current MVC routing pass */
    private static $routeMatchHookIds = [];

    /** @var array<string, bool> keyed by the Segment route's regex, which its parts determine */
    private static $segmentPresenceFollowsCaptures = [];

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
                        Integration::setComponentMetadata($span, 'laminas');
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
                Integration::setComponentMetadata($span, 'laminas');

                $rootSpan = root_span();
                $rootSpan->name = 'laminas.request';
                $rootSpan->service = \ddtrace_config_app_name('laminas');
                Integration::tagFrameworkServiceSource($rootSpan, 'laminas');
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
                Integration::setComponentMetadata($span, 'laminas');
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
                    Integration::setComponentMetadata($span, 'laminas');
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
                    Integration::setComponentMetadata($span, 'laminas', $service);
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

                $isTopLevelRouterMatch = $this instanceof \Laminas\Router\Http\TreeRouteStack
                    && !($this instanceof \Laminas\Router\Http\Part);

                if (
                    $isTopLevelRouterMatch
                    && $routeName !== null
                    && $routeName !== ''
                ) {
                    $httpRoute = LaminasIntegration::httpRouteTemplateFromNamedRouteStack($this, (string) $routeName);
                    if ($httpRoute !== null && $httpRoute !== '') {
                        $rootSpan->meta[Tag::HTTP_ROUTE] = $httpRoute;
                        if (function_exists('\datadog\appsec\is_enabled') && \datadog\appsec\is_enabled()
                            && dd_trace_env_config("DD_API_SECURITY_ENABLED")) {
                            // The routes Laminas matched, with what each matched (see
                            // $routeMatchRecords). The normalized route is built from them
                            // as elements, so static text is never read as route syntax.
                            // Without them (routing outside RouteListener::onRoute, or a
                            // route that consumed path without being recorded) it is
                            // omitted: http.route alone cannot tell a Literal's ':' from a
                            // parameter, nor which optionals took part.
                            $_records = LaminasIntegration::takeRouteMatchRecords($routeMatch);
                            $_signature = null;
                            if ($_records !== null) {
                                try {
                                    $_signature = LaminasIntegration::replaySignature($_records);
                                } catch (\Throwable $_ex) {
                                    unset($_ex);
                                }
                            }
                            if ($_signature !== null) {
                                // The signature determines the normalized route (see
                                // replaySignature()); the route name and http.route only
                                // make the key readable. Each part is length-prefixed so
                                // that no two (name, http.route, signature) triples share
                                // a key.
                                $cacheKey = "laminas\0" . \strlen((string) $routeName) . ':' . $routeName
                                    . \strlen($httpRoute) . ':' . $httpRoute . $_signature;
                                $normalizedRoute = \DDTrace\routing_cache_get($cacheKey);
                                if ($normalizedRoute === false) {
                                    try {
                                        $_elements = LaminasIntegration::replayRouteMatch($_records);
                                    } catch (\Throwable $_ex) {
                                        unset($_ex);
                                        $_elements = null;
                                    }
                                    $normalizedRoute = $_elements === null
                                        ? null
                                        : \DDTrace\Util\RouteNormalizer::normalizeElements($_elements);
                                    unset($_elements);
                                    if ($normalizedRoute !== null) {
                                        \DDTrace\routing_cache_set($cacheKey, $normalizedRoute);
                                    }
                                }
                                if ($normalizedRoute !== null) {
                                    $rootSpan->meta[Tag::APPSEC_NORMALIZED_ROUTE] = $normalizedRoute;
                                }
                            }
                            unset($_records, $_signature);
                        }
                    }
                }

                if (
                    function_exists('\datadog\appsec\push_addresses')
                    && $isTopLevelRouterMatch
                    && $routeName !== null
                    && $routeName !== ''
                ) {
                    $params = $routeMatch->getParams();
                    $pathParams = array_diff_key(
                        $params,
                        array_flip([
                            'controller',
                            'action',
                            '__NAMESPACE__',
                            '__CONTROLLER__',
                            'locale',
                        ])
                    );
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
                static function () {
                    // The recording hooks are only needed to normalize the route,
                    // so they exist only while routing with API Security enabled.
                    self::detachRouteMatchHooks();
                    if (
                        \function_exists('\datadog\appsec\is_enabled')
                        && \datadog\appsec\is_enabled()
                        && dd_trace_env_config("DD_API_SECURITY_ENABLED")
                    ) {
                        self::attachRouteMatchHooks();
                    }
                },
                static function ($This, $scope, $args) {
                    self::detachRouteMatchHooks();
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
                Integration::tagFrameworkServiceSource($span, 'laminas');
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
                    Integration::tagFrameworkServiceSource($span, 'laminas');
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
                Integration::tagFrameworkServiceSource($span, 'laminas');
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
                Integration::tagFrameworkServiceSource($span, 'laminas');
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
                Integration::tagFrameworkServiceSource($span, 'laminas');
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
                Integration::tagFrameworkServiceSource($span, 'laminas');
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
                Integration::tagFrameworkServiceSource($span, 'laminas');
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
                Integration::tagFrameworkServiceSource($span, 'laminas');
                $span->resource = $args[0];
                $span->meta[Tag::COMPONENT] = 'laminas';
            }
        );

        trace_method(
            'Laminas\Mvc\Controller\AbstractController',
            'forward',
            static function (SpanData $span, $args) {
                $span->name = 'laminas.controller.forward';
                Integration::setComponentMetadata($span, 'laminas');

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

        hook_method(
            'Laminas\Authentication\AuthenticationService',
            'authenticate',
            null,
            static function ($This, $scope, $args, $result) {
                if (!$result instanceof \Laminas\Authentication\Result) {
                    return;
                }

                $adapter = $args[0] ?? null;
                if (!$adapter) {
                    $adapter = $This->getAdapter() ?? null;
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
                $userLogin = self::getUserLogin($identity);
                $metadata = self::getUserMetadata($identity);
                $userId = self::getUserId($identity);

                if ($result->isValid()) {
                    if (!function_exists('\datadog\appsec\internal\track_user_login_success_event_automated')) {
                        return;
                    }

                    if ($userId === '') {
                        return;
                    }

                    \datadog\appsec\internal\track_user_login_success_event_automated(
                        'laminas',
                        $userLogin,
                        $userId,
                        $metadata
                    );
                    return;
                }

                if (!function_exists('\datadog\appsec\internal\track_user_login_failure_event_automated')) {
                    return;
                }

                $code = $result->getCode();
                $userExists = ($code === \Laminas\Authentication\Result::FAILURE_CREDENTIAL_INVALID);

                \datadog\appsec\internal\track_user_login_failure_event_automated(
                    'laminas',
                    $userLogin,
                    $userId,
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
                if (!function_exists('\datadog\appsec\internal\track_authenticated_user_event_automated')) {
                    return;
                }

                $userId = self::getUserId($identity);
                if ($userId === '') {
                    return;
                }

                \datadog\appsec\internal\track_authenticated_user_event_automated('laminas', $userId);
            }
        );

        return Integration::LOADED;
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
            $rp = new ReflectionProperty($matchedRoute, 'route');
            $rp->setAccessible(true);

            return (string) $rp->getValue($matchedRoute);
        }

        if ($matchedRoute instanceof \Laminas\Router\Http\Segment) {
            $rp = new ReflectionProperty($matchedRoute, 'parts');
            $rp->setAccessible(true);
            $parts = $rp->getValue($matchedRoute);

            return \is_array($parts) ? self::laminasSegmentPartsToRouteTemplate($parts) : null;
        }

        if ($matchedRoute instanceof \Laminas\Router\Http\Scheme) {
            return '';
        }

        if ($matchedRoute instanceof \Laminas\Router\Http\Placeholder) {
            return '';
        }

        if ($matchedRoute instanceof \Laminas\Router\Http\Regex) {
            $rp = new ReflectionProperty($matchedRoute, 'spec');
            $rp->setAccessible(true);
            $spec = (string) $rp->getValue($matchedRoute);

            return $spec !== '' ? $spec : null;
        }

        if ($matchedRoute instanceof \Laminas\Router\Http\Wildcard) {
            return '/*';
        }

        if ($matchedRoute instanceof \Laminas\Router\Http\Chain) {
            $buf = '';
            foreach (self::laminasGetChainRoutes($matchedRoute) as $chainedRoute) {
                $part = self::httpRouteTemplateFromMatchedRoute($chainedRoute, null);
                if ($part === null) {
                    return null;
                }
                $buf .= $part;
            }
            return $buf !== '' ? $buf : null;
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
        if (!($rootRouter instanceof \Laminas\Router\SimpleRouteStack)) {
            return;
        }
        $endpoints = self::collectLaminasRouteEndpointRows($rootRouter, $rootRouter, '');
        foreach ($endpoints as $row) {
            \DDTrace\add_endpoint($row['path'], 'http.request', $row['resourceName'], $row['method']);
        }
        \DDTrace\flush_endpoints();
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

    /**
     * Chain keeps child routes lazily in a private property; initialize before introspection.
     *
     * @return iterable<int|string, mixed>
     */
    private static function laminasGetChainRoutes(\Laminas\Router\Http\Chain $chain): iterable
    {
        $rp = new ReflectionProperty($chain, 'chainRoutes');
        $rp->setAccessible(true);
        $chainRoutes = $rp->getValue($chain);
        if ($chainRoutes !== null) {
            $chain->addRoutes($chainRoutes);
            $rp->setValue($chain, null);
        }

        return $chain->getRoutes();
    }

    private static function extractHttpVerbFromRoute($route): array
    {
        if ($route instanceof \Laminas\Router\Http\Chain) {
            foreach (self::laminasGetChainRoutes($route) as $chainRoute) {
                if ($chainRoute instanceof \Laminas\Router\Http\Method) {
                    $rp = new ReflectionProperty($chainRoute, 'verb');
                    $rp->setAccessible(true);
                    $verb = strtoupper(trim((string) $rp->getValue($chainRoute)));
                    return explode(',', $verb);
                }
            }

            return ['*'];
        }
        if (!($route instanceof \Laminas\Router\Http\Method)) {
            return ['*'];
        }
        $rp = new ReflectionProperty($route, 'verb');
        $rp->setAccessible(true);
        $verb = strtoupper(trim((string) $rp->getValue($route)));
        return explode(',', $verb);
    }

    private static function laminasGetRoutesFromStack($stack)
    {
        if (!($stack instanceof \Laminas\Router\SimpleRouteStack)) {
            return [];
        }

        return $stack->getRoutes();
    }

    private static function laminasGetNamedRouteFromStack($stack, string $name)
    {
        if (!($stack instanceof \Laminas\Router\SimpleRouteStack)) {
            return null;
        }

        return $stack->getRoute($name);
    }

    private static function laminasMaterializePartChildRoutes(\Laminas\Router\Http\Part $part): void
    {
        $rp = new ReflectionProperty($part, 'childRoutes');
        $rp->setAccessible(true);
        $childRoutes = $rp->getValue($part);
        if ($childRoutes !== null) {
            $part->addRoutes($childRoutes);
            $rp->setValue($part, null);
        }
    }

    private static function partHasDirectMethodChildren(\Laminas\Router\Http\Part $part): bool
    {
        self::laminasMaterializePartChildRoutes($part);
        foreach (self::laminasGetRoutesFromStack($part) as $child) {
            if ($child instanceof \Laminas\Router\Http\Method) {
                return true;
            }
        }
        return false;
    }

    private static function walkRouteStackCollectEndpointRows(
        $rootRouter,
        $currentStack,
        string $namePrefix,
        array &$rows
    ): void {
        foreach (self::laminasGetRoutesFromStack($currentStack) as $name => $route) {
            $qualifiedName = $namePrefix === '' ? (string) $name : $namePrefix . '/' . $name;
            $path = self::httpRouteTemplateFromNamedRouteStack($rootRouter, $qualifiedName);
            if ($path !== null && $path !== '') {
                $skipDirect = $route instanceof \Laminas\Router\Http\Part
                    && self::partHasDirectMethodChildren($route);
                if (!$skipDirect) {
                    $methods = self::extractHttpVerbFromRoute($route);
                    foreach ($methods as $m) {
                        $rows[] = [
                            'path' => $path,
                            'method' => $m,
                            'resourceName' => $m . ' ' . $path,
                        ];
                    }
                }
            }
            if ($route instanceof \Laminas\Router\Http\Part) {
                self::laminasMaterializePartChildRoutes($route);
                self::walkRouteStackCollectEndpointRows($rootRouter, $route, $qualifiedName, $rows);
            }
        }
    }

    /**
     * The path matches recorded on the RouteMatch the router returned, which
     * are then dropped with every other recorded match of this routing pass.
     * Null when nothing was recorded (no routing pass is being recorded) or
     * when the records do not account for the whole matched length: some
     * route matched path without being recorded (a route type laminas-router
     * does not ship, or a match() that builds its own RouteMatch), so what it
     * bound is unknown.
     *
     * @return array|null
     */
    public static function takeRouteMatchRecords($routeMatch)
    {
        $records = self::routeMatchRecords($routeMatch);
        self::$routeMatchRecords = null;
        if ($records === null) {
            return null;
        }

        $length = 0;
        foreach ($records as $record) {
            $length += $record[3];
        }

        return $length === $routeMatch->getLength() ? $records : null;
    }

    /**
     * Replays the recorded path matches to recover which captures took part,
     * which RouteMatch hides by merging route defaults. Each route's pattern
     * runs on the path and at the offset its match() got.
     *
     * Returns the route as RouteNormalizer::normalizeElements() elements:
     * Literal text as static, Segments with their optionals resolved to the
     * shape that matched, Regex specs aligned with the text the Regex matched,
     * and Wildcard as a catch-all tail. Null if any of them cannot be.
     *
     * @return array|null
     */
    public static function replayRouteMatch(array $records)
    {
        $elements = [];
        foreach ($records as list($route, $path, $offset, $length)) {
            if ($route instanceof \Laminas\Router\Http\Segment) {
                $match = self::inferLaminasSegmentMatch($route, $path, $offset);
                if ($match === null || $match['length'] !== $length) {
                    return null;
                }
                $routeElements = $match['elements'];
            } elseif ($route instanceof \Laminas\Router\Http\Regex) {
                $routeElements = self::laminasRegexElements($route, $path, $offset, $length);
            } elseif ($route instanceof \Laminas\Router\Http\Literal) {
                $routeElements = [[
                    'static',
                    (string) self::laminasReadProperty($route, \Laminas\Router\Http\Literal::class, 'route'),
                ]];
            } else {
                $routeElements = self::laminasWildcardElements($path, $offset, $length);
            }
            if ($routeElements === null) {
                return null;
            }
            array_push($elements, ...$routeElements);
        }

        return $elements;
    }

    /**
     * The routing cache key counterpart of replayRouteMatch(): one
     * length-prefixed entry per recorded match, which together determine the
     * normalized route. A Literal contributes its text; a Segment its regex
     * and parameter names, which its parts determine, and the indices of its
     * capture groups that took part; a Regex and a Wildcard their resolved
     * elements. A Segment with an optional whose presence leaves no capture
     * (e.g. [/draft]) contributes its resolved elements too, which needs the
     * full replay.
     *
     * Returns null where the replay cannot be done.
     */
    public static function replaySignature(array $records)
    {
        $signature = '';
        foreach ($records as list($route, $path, $offset, $length)) {
            if ($route instanceof \Laminas\Router\Http\Segment) {
                $routeData = self::laminasSegmentRouteData($route);
                if (self::laminasSegmentPresenceFollowsCaptures($routeData)) {
                    $participation = self::laminasSegmentParticipation($route, $path, $offset);
                    if ($participation === null || $participation['length'] !== $length) {
                        return null;
                    }
                    $entry = 'p' . $participation['groups'];
                } else {
                    $match = self::inferLaminasSegmentMatch($route, $path, $offset);
                    if ($match === null || $match['length'] !== $length) {
                        return null;
                    }
                    $entry = 't' . self::laminasElementsSignature($match['elements']);
                }
                $entry = 's' . self::laminasLengthPrefixed($routeData[0])
                    . self::laminasLengthPrefixed(...array_values($routeData[1])) . $entry;
            } elseif ($route instanceof \Laminas\Router\Http\Regex) {
                $elements = self::laminasRegexElements($route, $path, $offset, $length);
                if ($elements === null) {
                    return null;
                }
                $entry = 'r' . self::laminasElementsSignature($elements);
            } elseif ($route instanceof \Laminas\Router\Http\Literal) {
                $entry = 'l' . self::laminasReadProperty($route, \Laminas\Router\Http\Literal::class, 'route');
            } else {
                $elements = self::laminasWildcardElements($path, $offset, $length);
                if ($elements === null) {
                    return null;
                }
                $entry = 'w' . self::laminasElementsSignature($elements);
            }
            $signature .= self::laminasLengthPrefixed($entry);
        }

        return $signature;
    }

    /** Elements as a string that tells any two element lists apart. */
    private static function laminasElementsSignature(array $elements): string
    {
        $signature = '';
        foreach ($elements as $element) {
            $signature .= $element[0][0] . (isset($element[1]) ? self::laminasLengthPrefixed($element[1]) : '');
        }

        return $signature;
    }

    private static function laminasLengthPrefixed(string ...$strings): string
    {
        $buf = '';
        foreach ($strings as $string) {
            $buf .= \strlen($string) . ':' . $string;
        }

        return $buf;
    }

    /**
     * A Regex match as elements. The spec is the template Regex::assemble()
     * fills with parameters, so it describes the match only where it lines
     * up with the text the Regex matched: its static text verbatim, and each
     * placeholder of a capture that took part at that capture's offset. Only
     * the names of the regex's capture groups are placeholders; anything
     * else, such as the %C3% of an encoded character, is static text. Static
     * text right before a placeholder whose capture did not take part may be
     * missing from the URL by trailing separators, such as the '.' of
     * %id%.%format%: the regex holds them in the capture's optional. The
     * static elements are the text the URL has.
     *
     * Null when the spec does not line up, e.g. when it has text the regex
     * holds in a non-capturing optional, or a placeholder that names no
     * capture group, and when a capture that took part has no placeholder:
     * its position in the route is then unknown.
     *
     * @return array|null
     */
    private static function laminasRegexElements(
        \Laminas\Router\Http\Regex $route,
        string $urlPath,
        $offset,
        int $length
    ) {
        $regex = (string) self::laminasReadProperty($route, \Laminas\Router\Http\Regex::class, 'regex');
        // The spec property, not getSpec(), which a subclass could add.
        $spec = (string) self::laminasReadProperty($route, \Laminas\Router\Http\Regex::class, 'spec');
        $matches = self::laminasMatchAt($regex, $urlPath, $offset);
        if ($spec === '' || $matches === null || strlen($matches[0][0]) !== $length) {
            return null;
        }

        preg_match_all('/\(\?(?:P?<|\')([A-Za-z_][A-Za-z0-9_]*)[>\']/', $regex, $groupNames);
        $groups = array_flip($groupNames[1]);
        $tokens = [];
        $static = '';
        $specLength = strlen($spec);
        for ($i = 0; $i < $specLength; $i++) {
            if (
                $spec[$i] === '%'
                && preg_match('/\G%([A-Za-z_][A-Za-z0-9_]*)%/', $spec, $placeholder, 0, $i)
                && isset($groups[$placeholder[1]])
            ) {
                if ($static !== '') {
                    $tokens[] = ['static', $static];
                    $static = '';
                }
                // Regex::match() keeps the named captures that took part with
                // a non-empty value.
                $name = $placeholder[1];
                $tokens[] = isset($matches[$name]) && $matches[$name][1] >= 0 && $matches[$name][0] !== ''
                    ? ['param', $name, $matches[$name][0], $matches[$name][1]]
                    : ['absent'];
                $i += strlen($placeholder[0]) - 1;
                continue;
            }
            $static .= $spec[$i];
        }
        if ($static !== '') {
            $tokens[] = ['static', $static];
        }

        $placed = [];
        foreach ($tokens as $token) {
            if ($token[0] === 'param') {
                $placed[$token[1]] = true;
            }
        }
        foreach ($matches as $name => $capture) {
            if (\is_string($name) && $capture[1] >= 0 && $capture[0] !== '' && !isset($placed[$name])) {
                return null;
            }
        }

        $start = $offset ?? 0;
        $states = [[$start, []]];
        foreach ($tokens as $t => $token) {
            $nextStates = [];
            foreach ($states as list($position, $elements)) {
                if ($token[0] === 'absent') {
                    $nextStates[] = [$position, $elements];
                } elseif ($token[0] === 'param') {
                    if ($token[3] === $position) {
                        $elements[] = ['param', $token[1]];
                        $nextStates[] = [$position + strlen($token[2]), $elements];
                    }
                } else {
                    $text = $token[1];
                    $shortest = isset($tokens[$t + 1]) && $tokens[$t + 1][0] === 'absent'
                        ? strlen(rtrim($text, './-_~'))
                        : strlen($text);
                    for ($k = strlen($text); $k >= $shortest; $k--) {
                        $candidate = (string) substr($text, 0, $k);
                        if ($k === 0 || substr_compare($urlPath, $candidate, $position, $k) === 0) {
                            $nextStates[] = [
                                $position + $k,
                                $k === 0 ? $elements : array_merge($elements, [['static', $candidate]]),
                            ];
                        }
                    }
                }
            }
            $states = $nextStates;
        }
        foreach ($states as list($position, $elements)) {
            if ($position === $start + $length) {
                return $elements;
            }
        }

        return null;
    }

    /**
     * A Wildcard match as elements. Wildcard::match() takes the rest of the
     * path, which is empty, starts with the delimiter, or is a single piece
     * with no delimiter at all, continuing the URL segment before it.
     *
     * @return array|null
     */
    private static function laminasWildcardElements(string $urlPath, $offset, int $length)
    {
        $matched = (string) substr($urlPath, $offset ?? 0, $length);
        if ($matched === '') {
            return [];
        }
        if ($matched[0] === '/') {
            return [['static', '/'], ['tail']];
        }

        return strpos($matched, '/') === false ? [['tail']] : null;
    }

    /**
     * Installs the hooks that record, on the RouteMatch each returns, the path
     * matches of Literal, Wildcard, Segment and Regex routes, and carry them
     * through RouteMatch::merge(). See $routeMatchRecords.
     */
    private static function attachRouteMatchHooks(): void
    {
        self::$routeMatchRecords = new \SplObjectStorage();
        $record = static function (HookData $hook) {
            $routeMatch = $hook->returned;
            $request = $hook->args[0] ?? null;
            if (
                self::$routeMatchRecords !== null
                && $routeMatch instanceof \Laminas\Router\Http\RouteMatch
                && \is_object($request)
                && \method_exists($request, 'getUri')
            ) {
                self::$routeMatchRecords[$routeMatch] = [[
                    $hook->instance,
                    (string) $request->getUri()->getPath(),
                    $hook->args[1] ?? null,
                    $routeMatch->getLength(),
                ]];
            }
        };
        foreach (['Literal', 'Wildcard', 'Segment', 'Regex'] as $routeType) {
            self::$routeMatchHookIds[] = install_hook("Laminas\\Router\\Http\\$routeType::match", null, $record);
        }

        self::$routeMatchHookIds[] = install_hook(
            'Laminas\Router\Http\RouteMatch::merge',
            null,
            static function (HookData $hook) {
                $merged = self::routeMatchRecords($hook->args[0] ?? null);
                if ($merged !== null) {
                    self::$routeMatchRecords[$hook->instance] = \array_merge(
                        self::routeMatchRecords($hook->instance) ?? [],
                        $merged
                    );
                }
            }
        );
    }

    private static function detachRouteMatchHooks(): void
    {
        foreach (self::$routeMatchHookIds as $id) {
            remove_hook($id);
        }
        self::$routeMatchHookIds = [];
        self::$routeMatchRecords = null;
    }

    /** @return array|null the path matches recorded on $routeMatch */
    private static function routeMatchRecords($routeMatch)
    {
        if (self::$routeMatchRecords === null || !\is_object($routeMatch)) {
            return null;
        }

        return self::$routeMatchRecords[$routeMatch] ?? null;
    }

    /** Reads a protected property of a laminas-router object, declared by $class. */
    private static function laminasReadProperty($object, string $class, string $property)
    {
        static $readers = [];
        if (!isset($readers[$class])) {
            $readers[$class] = \Closure::bind(
                static function ($object, $property) {
                    return $object->$property;
                },
                null,
                $class
            );
        }

        return $readers[$class]($object, $property);
    }

    /**
     * Whether a Segment's resolved template follows from which of its captures
     * took part: true when every optional directly contains a parameter, so
     * its presence is that parameter's participation. [/draft] or the inner
     * optional of [/:a[/static[/:b]]] can be present with no capture.
     */
    private static function laminasSegmentPresenceFollowsCaptures(array $routeData): bool
    {
        $key = $routeData[0];
        if (!isset(self::$segmentPresenceFollowsCaptures[$key])) {
            self::$segmentPresenceFollowsCaptures[$key] = empty($routeData[3])
                && self::laminasOptionalsHaveDirectParameters($routeData[2], false);
        }

        return self::$segmentPresenceFollowsCaptures[$key];
    }

    private static function laminasOptionalsHaveDirectParameters(array $parts, bool $isOptional): bool
    {
        $hasParameter = false;
        foreach ($parts as $part) {
            if ($part[0] === 'parameter') {
                $hasParameter = true;
            } elseif ($part[0] === 'optional') {
                if (!self::laminasOptionalsHaveDirectParameters($part[1], true)) {
                    return false;
                }
            } elseif ($part[0] !== 'literal') {
                return false;
            }
        }

        return !$isOptional || $hasParameter;
    }

    /**
     * Runs a Segment's pattern as Segment::match() does and returns the
     * matched length and the capture groups that took part. A group that
     * took part with an empty value is marked with '='. Segment::match()
     * drops empty captures, so the full replay resolves such a request as if
     * the parameter were absent, but text the enclosing optional matched
     * still has to be accounted for: [:a] then resolves to the optional left
     * out, while [/:a] has a '/' that no shape explains and the tag is
     * omitted. Either way the result can differ from that of the group not
     * taking part, so the marker keeps these requests on a key of their own.
     */
    private static function laminasSegmentParticipation(
        \Laminas\Router\Http\Segment $route,
        string $urlPath,
        $pathOffset
    ) {
        $routeData = self::laminasSegmentRouteData($route);
        $matches = self::laminasMatchAt($routeData[0], $urlPath, $pathOffset);
        if ($matches === null) {
            return null;
        }

        $groups = [];
        foreach ($routeData[1] as $group => $name) {
            if (isset($matches[$group]) && $matches[$group][1] >= 0) {
                $groups[] = $matches[$group][0] === '' ? $group . '=' : $group;
            }
        }

        return ['groups' => implode(',', $groups), 'length' => strlen($matches[0][0])];
    }

    /** @return array{0: string, 1: array, 2: array, 3: array} regex, paramMap, parts, translationKeys */
    private static function laminasSegmentRouteData(\Laminas\Router\Http\Segment $route): array
    {
        static $read = null;
        if ($read === null) {
            $read = \Closure::bind(
                static function ($segment) {
                    return [
                        $segment->regex,
                        $segment->paramMap,
                        $segment->parts,
                        $segment->translationKeys,
                    ];
                },
                null,
                \Laminas\Router\Http\Segment::class
            );
        }

        return $read($route);
    }

    /**
     * Matches a route pattern the way Segment::match() and Regex::match() do:
     * the whole path when $pathOffset is null, from $pathOffset otherwise.
     *
     * @return array|null preg_match() matches with PREG_OFFSET_CAPTURE
     */
    private static function laminasMatchAt(string $regex, string $urlPath, $pathOffset)
    {
        $matches = [];
        if ($pathOffset === null) {
            $result = @preg_match('(^' . $regex . '$)', $urlPath, $matches, PREG_OFFSET_CAPTURE);
        } else {
            $result = @preg_match('(\G' . $regex . ')', $urlPath, $matches, PREG_OFFSET_CAPTURE, $pathOffset);
        }

        return $result === 1 ? $matches : null;
    }

    /**
     * Reproduce Segment::match() before Laminas merges route defaults.
     *
     * $pathOffset is the offset Laminas passed to Segment::match(): null
     * anchors the whole path, an integer matches from that offset on.
     *
     * The elements are those of RouteNormalizer::normalizeElements(): the
     * literal text and the parameters of the shape that matched.
     *
     * @return array{elements: array, params: array, length: int}|null
     */
    public static function inferLaminasSegmentMatch(
        \Laminas\Router\Http\Segment $route,
        string $urlPath,
        $pathOffset = null
    ) {
        $routeData = self::laminasSegmentRouteData($route);
        if (!empty($routeData[3])) {
            return null;
        }

        $matches = self::laminasMatchAt($routeData[0], $urlPath, $pathOffset);
        if ($matches === null) {
            return null;
        }
        $start = $pathOffset ?? 0;
        $end = $start + strlen($matches[0][0]);

        $captures = [];
        $params = [];
        foreach ($routeData[1] as $group => $name) {
            if (!isset($matches[$group][0]) || $matches[$group][0] === '') {
                continue;
            }
            $captures[$name][] = [
                'raw' => $matches[$group][0],
                'offset' => $matches[$group][1],
            ];
            $params[$name] = rawurldecode($matches[$group][0]);
        }

        $states = [[
            'offset' => $start,
            'elements' => [],
            'capture_indexes' => [],
        ]];
        $states = self::matchLaminasSegmentParts($routeData[2], $urlPath, $captures, $states);
        foreach ($states as $state) {
            if ($state['offset'] !== $end) {
                continue;
            }
            foreach ($captures as $name => $values) {
                if (($state['capture_indexes'][$name] ?? 0) !== count($values)) {
                    continue 2;
                }
            }
            return [
                'elements' => $state['elements'],
                'params' => $params,
                'length' => $end - $start,
            ];
        }

        return null;
    }

    private static function matchLaminasSegmentParts(
        array $parts,
        string $urlPath,
        array $captures,
        array $states
    ): array {
        foreach ($parts as $part) {
            $nextStates = [];
            foreach ($states as $state) {
                if ($part[0] === 'literal') {
                    $literal = $part[1];
                    if (substr($urlPath, $state['offset'], strlen($literal)) !== $literal) {
                        continue;
                    }
                    $state['offset'] += strlen($literal);
                    $state['elements'][] = ['static', $literal];
                    $nextStates[] = $state;
                } elseif ($part[0] === 'parameter') {
                    $name = $part[1];
                    $captureIndex = $state['capture_indexes'][$name] ?? 0;
                    if (!isset($captures[$name][$captureIndex])) {
                        continue;
                    }
                    $capture = $captures[$name][$captureIndex];
                    if ($capture['offset'] !== $state['offset']) {
                        continue;
                    }
                    $state['offset'] += strlen($capture['raw']);
                    $state['elements'][] = ['param', $name];
                    $state['capture_indexes'][$name] = $captureIndex + 1;
                    $nextStates[] = $state;
                } elseif ($part[0] === 'optional') {
                    $optionalStates = self::matchLaminasSegmentParts(
                        $part[1],
                        $urlPath,
                        $captures,
                        [$state]
                    );
                    foreach ($optionalStates as $optionalState) {
                        $nextStates[] = $optionalState;
                    }
                    $nextStates[] = $state;
                } else {
                    // Translated literals require the router's match options. Fall back
                    // rather than attempting a potentially inaccurate reconstruction.
                    return [];
                }
            }
            $states = $nextStates;
            if (empty($states)) {
                break;
            }
        }

        return $states;
    }

    /** @var array<string, string|null> cached template lookups keyed by stack-hash + name */
    private static $namedStackTemplateCache = [];

    public static function httpRouteTemplateFromNamedRouteStack($stack, string $matchedName): ?string
    {
        $cacheKey = \spl_object_hash($stack) . "\0" . $matchedName;
        if (\array_key_exists($cacheKey, self::$namedStackTemplateCache)) {
            return self::$namedStackTemplateCache[$cacheKey];
        }
        return self::$namedStackTemplateCache[$cacheKey]
            = self::computeHttpRouteTemplateFromNamedRouteStack($stack, $matchedName);
    }

    private static function computeHttpRouteTemplateFromNamedRouteStack($stack, string $matchedName): ?string
    {
        $segments = \explode('/', $matchedName, 2);
        $route = self::laminasGetNamedRouteFromStack($stack, $segments[0]);
        if ($route === null) {
            return null;
        }

        $hasChild = isset($segments[1]);

        if ($route instanceof \Laminas\Router\Http\Part) {
            self::laminasMaterializePartChildRoutes($route);
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
            return null;
        }

        return self::httpRouteTemplateFromMatchedRoute($route, null);
    }

    public static function partRouteBaseTemplate(\Laminas\Router\Http\Part $part): ?string
    {
        $rp = new ReflectionProperty($part, 'route');
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
