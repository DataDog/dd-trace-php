<?php

namespace DDTrace\Integrations\Laravel;

use DDTrace\GlobalTracer;
use DDTrace\SpanData;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\Tag;
use DDTrace\Type;

/**
 * The base Laravel integration which delegates loading to the appropriate integration version.
 */
class LaravelSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'laravel';

    /**
     * @var string
     */
    private $serviceName;

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

    /**
     * @return int
     */
    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return SandboxedIntegration::NOT_LOADED;
        }

        $rootScope = GlobalTracer::get()->getRootScope();
        $rootSpan = null;

        if (null === $rootScope || null === ($rootSpan = $rootScope->getSpan())) {
            return SandboxedIntegration::NOT_LOADED;
        }

        $integration = $this;

        \dd_trace_method(
            'Illuminate\Foundation\Application',
            'handle',
            function (SpanData $span, $args, $response) use ($rootSpan, $integration) {
                // Overwriting the default web integration
                $rootSpan->overwriteOperationName('laravel.request');
                $rootSpan->setIntegration($integration);
                $rootSpan->setTraceAnalyticsCandidate();
                if (\method_exists($response, 'getStatusCode')) {
                    $rootSpan->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode());
                }
                $rootSpan->setTag(Tag::SERVICE_NAME, $integration->getServiceName());

                $span->name = 'laravel.application.handle';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                $span->resource = 'Illuminate\Foundation\Application@handle';
            }
        );

        \dd_trace_method(
            'Illuminate\Routing\Router',
            'findRoute',
            function (SpanData $span, $args, $route) use ($rootSpan, $integration) {
                if (null === $route) {
                    return false;
                }

                list($request) = $args;

                // Overwriting the default web integration
                $rootSpan->setIntegration($integration);
                $rootSpan->setTraceAnalyticsCandidate();
                $rootSpan->setTag(
                    Tag::RESOURCE_NAME,
                    $route->getActionName() . ' ' . ($route->getName() ?: 'unnamed_route')
                );
                $rootSpan->setTag('laravel.route.name', $route->getName());
                $rootSpan->setTag('laravel.route.action', $route->getActionName());
                $rootSpan->setTag('http.url', $request->url());
                $rootSpan->setTag('http.method', $request->method());

                return false;
            }
        );

        \dd_trace_method(
            'Illuminate\Routing\Route',
            'run',
            function (SpanData $span) use ($integration) {
                $span->name = 'laravel.action';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                $span->resource = $this->uri;
                $integration->addIntegrationInfo($span);
            }
        );

        \dd_trace_method(
            'Symfony\Component\HttpFoundation\Response',
            'setStatusCode',
            function (SpanData $span, $args) use ($rootSpan) {
                $rootSpan->setTag(Tag::HTTP_STATUS_CODE, $args[0]);
                return false;
            }
        );

        \dd_trace_method('Illuminate\Events\Dispatcher', 'fire', function (SpanData $span, $args) use ($integration) {
            $span->name = 'laravel.event.handle';
            $span->type = Type::WEB_SERVLET;
            $span->service = $integration->getServiceName();
            $span->resource = $args[0];
            $integration->addIntegrationInfo($span);
        });

        \dd_trace_method('Illuminate\View\View', 'render', function (SpanData $span) use ($integration) {
            $span->name = 'laravel.view.render';
            $span->type = Type::WEB_SERVLET;
            $span->service = $integration->getServiceName();
            $span->resource = $this->view;
            $integration->addIntegrationInfo($span);
        });

        \dd_trace_method(
            'Illuminate\View\Engines\CompilerEngine',
            'get',
            function (SpanData $span, $args) use ($integration) {
                $span->name = 'laravel.view';
                $span->type = Type::WEB_SERVLET;
                $span->service = $integration->getServiceName();
                if (isset($args[0]) && \is_string($args[0])) {
                    $span->resource = $args[0];
                }
                $span->meta['integration.name'] = LaravelSandboxedIntegration::NAME;
            }
        );

        \dd_trace_method(
            'Illuminate\Foundation\ProviderRepository',
            'load',
            function (SpanData $span) use ($rootSpan, $integration) {
                $serviceName = $integration->getServiceName();
                $span->name = 'laravel.provider.load';
                $span->type = Type::WEB_SERVLET;
                $span->service = $serviceName;
                $span->resource = 'Illuminate\Foundation\ProviderRepository::load';
                $rootSpan->setIntegration($integration);
                $rootSpan->overwriteOperationName('laravel.request');
                $rootSpan->setTag(Tag::SERVICE_NAME, $serviceName);
            }
        );

        \dd_trace_method(
            'Illuminate\Console\Application',
            '__construct',
            function () use ($rootSpan, $integration) {
                $rootSpan->setIntegration($integration);
                $rootSpan->overwriteOperationName('laravel.artisan');
                $rootSpan->setTag(
                    Tag::RESOURCE_NAME,
                    !empty($_SERVER['argv'][1]) ? 'artisan ' . $_SERVER['argv'][1] : 'artisan'
                );
                return false;
            }
        );

        \dd_trace_method(
            'Symfony\Component\Console\Application',
            'renderException',
            function (SpanData $span, $args) use ($rootSpan) {
                $rootSpan->setError($args[0]);
                return false;
            }
        );

        return SandboxedIntegration::LOADED;
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
}
