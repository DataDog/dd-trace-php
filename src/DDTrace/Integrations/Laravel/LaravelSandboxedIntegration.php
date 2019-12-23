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

        dd_trace_method(
            'Illuminate\Routing\Events\RouteMatched',
            '__construct',
            function (SpanData $span, $args) use ($integration, $rootSpan) {
                list($route, $request) = $args;
                // Overwriting the default web integration
                $rootSpan->setIntegration($integration);
                $rootSpan->setTraceAnalyticsCandidate();
                $rootSpan->setTag(
                    Tag::RESOURCE_NAME,
                    $route->getActionName() . ' ' . (Route::currentRouteName() ?: 'unnamed_route')
                );
                $rootSpan->setTag('laravel.route.name', Route::currentRouteName());
                $rootSpan->setTag('laravel.route.action', $route->getActionName());
                $rootSpan->setTag('http.url', $request->url());
                $rootSpan->setTag('http.method', $request->method());

                return false;
            }
        );

        return SandboxedIntegration::LOADED;
    }
}
