<?php


namespace DDTrace\Integrations\Laminas;

// TODO: Tag::COMPONENT
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use Laminas\EventManager\EventInterface;
use Laminas\Mvc\MvcEvent;
use Laminas\Router\RouteMatch;
use Laminas\Stdlib\RequestInterface;
use Laminas\View\Model\ModelInterface;

use function DDTrace\hook_method;
use function DDTrace\trace_method;

class LaminasIntegration extends Integration
{
    const NAME = 'laminas';

    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return Integration::NOT_LOADED;
        }

        $rootSpan = \DDTrace\root_span();

        if (is_null($rootSpan)) {
            return Integration::NOT_LOADED;
        }


        $events = [
            'bootstrap',
            'dispatch',
            'dispatch.error',
            'finish',
            'render',
            'render.error',
            'route',

            'mergeConfig',
            'loadModules',
            'loadModule.resolve',
            'loadModule',
            'loadModules.post',

            'renderer',
            'renderer.post',
            'response',

            'sendResponse'
        ];

        $integration = $this;

        trace_method(
            'Laminas\Mvc\Application',
            'init',
            function (SpanData $span) use ($integration) {
                $span->name = 'laminas.application.init';
                $span->resource = 'laminas.application.init';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('laminas');
            }
        );

        // Overall application flow
        trace_method(
            'Laminas\Mvc\Application',
            'bootstrap',
            function (SpanData $span) {
                $span->name = 'laminas.application.bootstrap';
                $span->resource = 'laminas.application.bootstrap';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('laminas');
            }
        );

        trace_method(
            'Laminas\EventManager\EventManager',
            'triggerListeners',
            [
                'prehook' => function (SpanData $span, $args) use ($events, $rootSpan) {
                    /** @var EventInterface $event */
                    $event = $args[0];
                    $eventName = $event->getName();

                    // If the event is not an MVC one, don't start a span
                    if (!in_array($eventName, $events)) {
                        return false;
                    }

                    $span->name = "laminas.event.$eventName";
                    $span->service = \ddtrace_config_app_name('laminas');
                }
            ]
        );

        trace_method(
            'Laminas\Mvc\Application',
            'run',
            [
                'prehook' => function (SpanData $span) use ($rootSpan) {
                    $service = \ddtrace_config_app_name('laminas');
                    $rootSpan->name = 'laminas.request';
                    $rootSpan->service = $service;
                    $rootSpan->meta[Tag::SPAN_KIND] = 'server';
                    $rootSpan->meta[Tag::COMPONENT] = LaminasIntegration::NAME;

                    $span->name = 'laminas.application.run';
                    $span->resource = 'laminas.application.run';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $service;
                }
            ]
        );

        // MvcEvent::EVENT_ROUTE
        trace_method(
            'Laminas\Router\RouteInterface',
            'match',
            function (SpanData $span, $args, $retval) use ($rootSpan) {
                $span->name = 'laminas.route.match';
                $span->resource = \get_class($this) . '@match';

                /** @var RequestInterface $request */
                $request = $args[0];

                $method = $request->getMethod();

                /** @var RouteMatch $routeMatch */
                $routeMatch = $retval;
                $routeName = $routeMatch->getMatchedRouteName();
                $action = $routeMatch->getParam('action');
                $controller = $routeMatch->getParam('controller');

                if (method_exists($controller, $action . 'Action')) {
                    trace_method(
                        $controller,
                        $action . "Action",
                        function (SpanData $span) use ($controller, $action) {
                            $span->name = 'laminas.controller.action';
                            $span->resource = "$controller@{$action}Action";
                        }
                    );
                }

                $rootSpan->resource = "$controller@$action $routeName";

                $rootSpan->meta[Tag::HTTP_METHOD] = $method;
                $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize($request->getUriString());
                $rootSpan->meta['laminas.route.name'] = $routeName;
                $rootSpan->meta['laminas.route.action'] = "$controller@$action";
            }
        );

        // MvcEvent:EVENT_DISPATCH & MvcEvent::EVENT_DISPATCH_ERROR
        trace_method(
            'Laminas\Stdlib\DispatchableInterface',
            'dispatch',
            function (SpanData $span) use ($rootSpan) {
                $span->name = 'laminas.controller.dispatch';
                $span->resource = \get_class($this);
            }
        );

        hook_method(
            'Laminas\Mvc\Controller\AbstractController',
            'onDispatch',
            null,
            function ($This, $score, $args) use ($rootSpan, $integration) {
                /** @var MvcEvent $event */
                $event = $args[0];

                $exception = $event->getParam('exception');
                if ($exception) {
                    $integration->setError($rootSpan, $exception);
                }
            }
        );

        // MvcEvent::EVENT_RENDER & MvcEvent::EVENT_RENDER_ERROR
        trace_method(
            'Laminas\Mvc\Application',
            'completeRequest',
            function (SpanData $span, $args) use ($rootSpan, $integration) {
                $span->name = 'laminas.application.completeRequest';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;

                /** @var MvcEvent $event */
                $event = $args[0];

                $request = $event->getRequest();
                $method = $request->getMethod();

                $rootSpan->meta[Tag::HTTP_METHOD] = $method;
                $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize($request->getUriString());
            }
        );

        trace_method(
            'Laminas\View\Renderer\RendererInterface',
            'render',
            function (SpanData $span, $args) use ($rootSpan, $integration) {
                $span->name = 'laminas.templating.render';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;
                $span->resource = \get_class($this);

                $nameOrModel = $args[0];
                if (is_string($nameOrModel)) {
                    $span->resource = $nameOrModel;
                } else {
                    /** @var ModelInterface $nameOrModel */
                    $span->resource = $nameOrModel->getTemplate();
                }
            }
        );

        trace_method(
            'Laminas\View\View',
            'render',
            function (SpanData $span) use ($rootSpan, $integration) {
                $span->name = 'laminas.view.render';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;
            }
        );

        trace_method(
            'Laminas\View\Model\JsonModel',
            'serialize',
            function (SpanData $span) use ($rootSpan, $integration) {
                $span->name = 'laminas.view.model.serialize';
                $span->resource = \get_class($this);
            }
        );

        trace_method(
            'Laminas\Mvc\View\Http\DefaultRenderingStrategy',
            'render',
            function (SpanData $span, $args) use ($rootSpan, $integration) {
                $span->name = 'laminas.view.http.renderer';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;
                $span->resource = \get_class($this) . '@render';

                /** @var MvcEvent $event */
                $event = $args[0];

                $exception = $event->getParam('exception');
                if ($exception) {
                    $integration->setError($rootSpan, $exception);
                }
            }
        );

        trace_method(
            'Laminas\Mvc\View\Console\DefaultRenderingStrategy',
            'render',
            function (SpanData $span, $args) use ($rootSpan, $integration) {
                $span->name = 'laminas.view.console.renderer';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;
                $span->resource = \get_class($this) . '@render';

                /** @var MvcEvent $event */
                $event = $args[0];

                $exception = $event->getParam('exception');
                if ($exception) {
                    $integration->setError($rootSpan, $exception);
                }
            }
        );

        // Generic Error Handling
        trace_method(
            'Laminas\Mvc\MvcEvent',
            'setError',
            function (SpanData $span, $args, $retval) use ($rootSpan, $integration) {
                $span->name = 'laminas.mvcEvent.setError';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;

                /** @var MvcEvent $event */
                $event = $retval[0];

                $exception = $event->getParam('exception');
                if ($exception) {
                    $integration->setError($rootSpan, $exception);
                }
            }
        );

        // Misc.
        trace_method(
            'Laminas\Mvc\Controller\PluginManager',
            'get',
            function (SpanData $span, $args) {
                $span->name = 'laminas.controller.pluginManager.get';
                $span->resource = $args[0];
            }
        );

        trace_method(
            'Laminas\Mvc\Controller\AbstractController',
            'forward',
            function (SpanData $span, $args) {
                $span->name = 'laminas.controller.forward';

                $controllerName = $args[0];
                if (isset($args[1]) && isset($args[1]['action'])) {
                    $actionName = $args[1]['action'];
                    $span->resource = $controllerName . '@' . $actionName;
                } else {
                    $span->resource = $controllerName;
                }
            }
        );

        return Integration::LOADED;
    }
}
