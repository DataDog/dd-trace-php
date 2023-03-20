<?php

namespace DDTrace\Integrations\Laminas;

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
use function DDTrace\trace_method;

class LaminasIntegration extends Integration
{
    const NAME = 'laminas';

    public function getName()
    {
        return self::NAME;
    }

    public $listeners;

    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return Integration::NOT_LOADED;
        }

        $rootSpan = \DDTrace\root_span();

        if (is_null($rootSpan)) {
            return Integration::NOT_LOADED;
        }


        $MVCEvents = [];
        if (class_exists('Laminas\Mvc\MvcEvent')) {
            // Retrieve all MVC events
            $reflection = new \ReflectionClass('Laminas\Mvc\MvcEvent');
            foreach ($reflection->getConstants() as $key => $value) {
                if (strpos($key, 'EVENT_') === 0) {
                    $MVCEvents[] = $value;
                }
            }
        }

        $integration = $this;

        trace_method(
            'Laminas\EventManager\EventManager',
            'triggerListeners',
            [
                'prehook' => function (SpanData $span, $args) use ($MVCEvents, $rootSpan) {
                    /** @var EventInterface $event */
                    $event = $args[0];
                    $eventName = $event->getName();

                    // If the event is not an MVC one, don't start a span
                    if (!in_array($eventName, $MVCEvents)) {
                        return false;
                    }

                    $span->name = "laminas.event.$eventName";
                }
            ]
        );

        // MvcEvent::EVENT_ROUTE
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

                $rootSpan->resource = "$controller@$action $routeName";

                $rootSpan->meta[Tag::HTTP_METHOD] = $method;
                $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize($request->getUriString());
                $rootSpan->meta['laminas.route.name'] = $routeName;
                $rootSpan->meta['laminas.route.action'] = "$controller@$action";
            }
        );


        trace_method(
            'Laminas\Mvc\RouteListener',
            'onRoute',
            function (SpanData $span, $args) use ($rootSpan, $integration) {
                $span->name = 'laminas.route.listener';
                $span->resource = \get_class($this);
            }
        );

        trace_method(
            'Laminas\EventManager\AbstractListenerAggregate',
            'onRoute',
            function (SpanData $span) {
                $span->name = 'laminas.route.listener';
                $span->resource = \get_class($this);
            }
        );

        // MvcEvent:EVENT_DISPATCH & MvcEvent::EVENT_DISPATCH_ERROR
        trace_method(
            'Laminas\Stdlib\DispatchableInterface',
            'dispatch',
            function (SpanData $span, $args) use ($rootSpan) {
                $span->name = 'laminas.controller.dispatch';
                $span->resource = \get_class($this);
            }
        );

        trace_method(
            'Laminas\Mvc\Controller\AbstractController',
            'onDispatch',
            function (SpanData $span, $args) use ($rootSpan, $integration) {
                $span->name = 'laminas.controller.action';
                $span->meta[Tag::COMPONENT] = LaminasIntegration::NAME;
                $span->resource = \get_class($this);

                /** @var MvcEvent $event */
                $event = $args[0];

                $routeMatch = $event->getRouteMatch();
                if ($routeMatch) {
                    $action = $routeMatch->getParam('action');
                    $controller = $routeMatch->getParam('controller');
                    $span->resource = "$controller@$action";
                }

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
                $span->name = 'laminas.application.complete_request';
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
                $span->name = 'laminas.view.renderer';
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

                $span->meta[Tag::COMPONENT] = LaminasIntegration::NAME;
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

        // MvcEvent::EVENT_FINISH
        trace_method(
            'Laminas\Mvc\SendResponseListener',
            'sendResponse',
            function (SpanData $span, $args) use ($rootSpan, $integration) {
                $span->name = 'laminas.send_response';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;
                $span->resource = 'laminas.send_response';

                /** @var MvcEvent $event */
                $event = $args[0];

                $exception = $event->getParam('exception');
                if ($exception) {
                    $integration->setError($rootSpan, $exception);
                }
            }
        );

        trace_method(
            'Laminas\Mvc\MvcEvent',
            'setError',
            function (SpanData $span, $args, $retval) use ($rootSpan, $integration) {
                $span->name = 'laminas.mvc_event.set_error';
                $span->service = \ddtrace_config_app_name('laminas');
                $span->type = Type::WEB_SERVLET;
                $span->resource = 'laminas.mvc_event.set_error';

                /** @var MvcEvent $event */
                $event = $retval[0];

                $exception = $event->getParam('exception');
                if ($exception) {
                    $integration->setError($rootSpan, $exception);
                }
            }
        );

        return Integration::LOADED;
    }
}
