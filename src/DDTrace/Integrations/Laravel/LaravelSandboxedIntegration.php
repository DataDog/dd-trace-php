<?php

namespace DDTrace\Integrations\Laravel;

use DDTrace\Configuration;
use DDTrace\GlobalTracer;
use DDTrace\SpanData;
use DDTrace\Integrations\Laravel\V5\LaravelIntegrationLoader;
use DDTrace\Integrations\SandboxedIntegration;
use DDTrace\Util\Versions;
use DDTrace\Integrations\Integration;
use DDTrace\Integrations\Laravel\LaravelIntegration;
use DDTrace\Scope;
use DDTrace\Tag;
use DDTrace\Type;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;

/**
 * The base Laravel integration which delegates loading to the appropriate integration version.
 */
class LaravelSandboxedIntegration extends SandboxedIntegration
{
    const NAME = 'laravel';

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
        if (!Configuration::get()->isIntegrationEnabled(LaravelSandboxedIntegration::NAME)) {
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
            function () use ($rootSpan, $integration) {
                $rootSpan->overwriteOperationName('laravel.request');
                // Overwriting the default web integration
                $rootSpan->setIntegration($integration);
                $rootSpan->setTraceAnalyticsCandidate();
                $rootSpan->setTag(Tag::HTTP_STATUS_CODE, $response->getStatusCode());
                $rootSpan->setTag(Tag::SERVICE_NAME, $integration->getAppName());

                return false;
            }
        );

        \dd_trace_method(
            'Illuminate\Routing\Router',
            'findRoute',
            function ($span, $args, $route) use ($rootSpan, $integration) {
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
                $span->service = $integration->getAppName();
                $span->resource = $this->uri;
            }
        );

        return SandboxedIntegration::LOADED;
    }

    public function getAppName()
    {
        $appName = Configuration::get()->appName();
        if (empty($appName) && is_callable('config')) {
            $appName = config('app.name');
        }
        return $appName ?: 'laravel';
    }
}
