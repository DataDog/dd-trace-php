<?php

namespace DDTrace\Integrations\CakePHP;

use DDTrace\Integrations\CakePHP\V2\CakePHPIntegrationLoader as CakePHPIntegrationLoaderV2;
use DDTrace\Integrations\CakePHP\V3\CakePHPIntegrationLoader as CakePHPIntegrationLoaderV3;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;

class CakePHPIntegration extends Integration
{
    const NAME = 'cakephp';

    public $appName;
    public $rootSpan;
    public $setRootSpanInfoFn;
    public $handleExceptionFn;
    public $setStatusCodeFn;
    public $parseRouteFn;

    /**
     * @return string The integration name.
     */
    public function getName()
    {
        return self::NAME;
    }

    public function init(): int
    {
        $integration = $this;

        $integration->setRootSpanInfoFn = function () use ($integration) {
            $rootSpan = \DDTrace\root_span();
            if ($rootSpan === null) {
                return;
            }

            $integration->appName = \ddtrace_config_app_name(CakePHPIntegration::NAME);
            $integration->addTraceAnalyticsIfEnabled($rootSpan);
            $rootSpan->service = $integration->appName;
            if ('cli' === PHP_SAPI) {
                $rootSpan->name = 'cakephp.console';
                $rootSpan->resource = !empty($_SERVER['argv'][1])
                    ? 'cake_console ' . $_SERVER['argv'][1]
                    : 'cake_console';
            } else {
                $rootSpan->name = 'cakephp.request';
                $rootSpan->meta[Tag::SPAN_KIND] = 'server';
            }
            $rootSpan->meta[Tag::COMPONENT] = CakePHPIntegration::NAME;
        };

        $integration->handleExceptionFn = function ($This, $scope, $args) use ($integration) {
            $rootSpan = \DDTrace\root_span();
            if ($rootSpan !== null) {
                $integration->setError($rootSpan, $args[0]);
            }
        };

        $integration->setStatusCodeFn =  function ($This, $scope, $args, $retval) use ($integration) {
            $rootSpan = \DDTrace\root_span();
            if ($rootSpan) {
                $rootSpan->meta[Tag::HTTP_STATUS_CODE] = $retval;
            }
        };

        $integration->parseRouteFn = function ($app, $appClass, $args, $retval) use ($integration) {
            if (!$retval) {
                return;
            }

            $rootSpan = \DDTrace\root_span();
            if ($rootSpan !== null) {
                $rootSpan->meta[Tag::HTTP_ROUTE] = $app->template;
            }
        };

        $loader = class_exists('Cake\Http\Server') // Only exists in V3+
            ? new CakePHPIntegrationLoaderV3()
            : new CakePHPIntegrationLoaderV2();
        return $loader->load($integration);
    }
}
