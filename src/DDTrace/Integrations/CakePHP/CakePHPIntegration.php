<?php

namespace DDTrace\Integrations\CakePHP;

use DDTrace\Integrations\CakePHP\V2\CakePHPIntegrationLoader as CakePHPIntegrationLoaderV2;
use DDTrace\Integrations\CakePHP\V3\CakePHPIntegrationLoader as CakePHPIntegrationLoaderV3;
use DDTrace\Integrations\Integration;
use DDTrace\Tag;

class CakePHPIntegration extends Integration
{
    const NAME = 'cakephp';

    public static $appName;
    public static $rootSpan;
    public static $setRootSpanInfoFn;
    public static $handleExceptionFn;
    public static $setStatusCodeFn;
    public static $parseRouteFn;

    /**
     * {@inheritdoc}
     */
    public static function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    public static function init(): int
    {
        self::$setRootSpanInfoFn = static function () {
            $rootSpan = \DDTrace\root_span();
            if ($rootSpan === null) {
                return;
            }

            self::$appName = \ddtrace_config_app_name(CakePHPIntegration::NAME);
            self::addTraceAnalyticsIfEnabled($rootSpan);
            $rootSpan->service = self::$appName;
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

        self::$handleExceptionFn = static function ($This, $scope, $args) {
            $rootSpan = \DDTrace\root_span();
            if ($rootSpan !== null) {
                $rootSpan->exception = $args[0];
            }
        };

        self::$setStatusCodeFn =  static function ($This, $scope, $args, $retval) {
            $rootSpan = \DDTrace\root_span();
            if ($rootSpan) {
                $rootSpan->meta[Tag::HTTP_STATUS_CODE] = $retval;
            }
        };

        self::$parseRouteFn = static function ($app, $appClass, $args, $retval) {
            if (!$retval) {
                return;
            }

            $rootSpan = \DDTrace\root_span();
            if ($rootSpan !== null) {
                $rootSpan->meta[Tag::HTTP_ROUTE] = $app->template;
            }
        };

        return class_exists('Cake\Http\Server') // Only exists in V3+
            ? CakePHPIntegrationLoaderV3::load()
            : CakePHPIntegrationLoaderV2::load();
    }
}
