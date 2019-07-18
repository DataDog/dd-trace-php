<?php

namespace DDTrace\Integrations\Nette;

use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\Tag;
use DDTrace\Type;
use Nette\Application\Application;

class NetteLoader
{

    const NAME = 'nette';

    /**
     * @var \DDTrace\Contracts\Span
     */
    public $rootSpan;

    public function load(\DDTrace\Integrations\Nette\NetteIntegration $integration)
    {
        $this->rootSpan = GlobalTracer::get()->getRootScope()->getSpan();

        // Overwrite the default web integration
        $this->rootSpan->setIntegration($integration);
        $this->rootSpan->setTraceAnalyticsCandidate();
        $this->rootSpan->overwriteOperationName('nette.request');
        $this->rootSpan->setTag(Tag::SERVICE_NAME, $this->getAppName());

        $loader = $this;

        dd_trace('Nette\Configurator', 'createContainer', function () use ($integration) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'nette.configurator.createContainer');
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);

            return include __DIR__ . '/../../try_catch_finally.php';
        });

        dd_trace('Nette\Configurator', 'createRobotLoader', function () use ($integration) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'nette.configurator.createRobotLoader');
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);

            return include __DIR__ . '/../../try_catch_finally.php';
        });

        dd_trace('Nette\Application\Application', 'run', function () use ($loader, $integration) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'nette.application.run');
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);

            $r = include __DIR__ . '/../../try_catch_finally.php';
            $loader->rootSpan->setTag(Tag::HTTP_STATUS_CODE, http_response_code());
            return $r;
        });


        dd_trace(
            'Nette\Application\Application',
            'onError',
            function (Application $application, \Exception $exception = null) use ($loader) {
                $loader->rootSpan->setError($exception);
                return include __DIR__ . '/../../try_catch_finally.php';
            }
        );

        dd_trace(
            'Nette\Application\UI\Presenter',
            'run',
            function (\Nette\Application\Request $request) use ($loader, $integration) {
                $tracer = GlobalTracer::get();
                if ($tracer->limited()) {
                    return dd_trace_forward_call();
                }

                $scope = $tracer->startIntegrationScopeAndSpan($integration, 'nette.presenter.run');
                $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);

                $loader->rootSpan->setTag(Tag::HTTP_METHOD, $request->getMethod());
                $loader->rootSpan->setTag('nette.route.presenter', $request->getPresenterName());
                $loader->rootSpan->setTag('nette.route.action', $request->getParameter('action'));

                /** @var \Nette\Application\IResponse $response */
                $response = include __DIR__ . '/../../try_catch_finally.php';

                return $response;
            }
        );

        dd_trace('Nette\Application\Routers\RouteList', 'match', function () use ($integration) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'nette.router.match');
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
            return include __DIR__ . '/../../try_catch_finally.php';
        });

        // Latte template engine traces
        dd_trace('Latte\Engine', 'createTemplate', function ($name) use ($integration) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'nette.latte.createTemplate');
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
            $scope->getSpan()->setTag('nette.latte.templateName', $name);
            return include __DIR__ . '/../../try_catch_finally.php';
        });

        dd_trace('Latte\Engine', 'render', function ($name) use ($integration) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'nette.latte.render');
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
            $scope->getSpan()->setTag('nette.latte.templateName', $name);
            return include __DIR__ . '/../../try_catch_finally.php';
        });

        dd_trace('Latte\Engine', 'renderToString', function ($name) use ($integration) {
            $tracer = GlobalTracer::get();
            if ($tracer->limited()) {
                return dd_trace_forward_call();
            }

            $scope = $tracer->startIntegrationScopeAndSpan($integration, 'nette.latte.renderToString');
            $scope->getSpan()->setTag(Tag::SPAN_TYPE, Type::WEB_SERVLET);
            $scope->getSpan()->setTag('nette.latte.templateName', $name);
            return include __DIR__ . '/../../try_catch_finally.php';
        });
    }

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    private function getAppName()
    {
        return Configuration::get()->appName(self::NAME);
    }
}
