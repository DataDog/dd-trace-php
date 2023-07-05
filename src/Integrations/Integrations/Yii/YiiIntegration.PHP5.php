<?php

namespace DDTrace\Integrations\Yii;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Versions;
use yii\helpers\Url;

class YiiIntegration extends Integration
{
    const NAME = 'yii';

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
     * {@inheritdoc}
     */
    public function init()
    {
        if (!self::shouldLoad(self::NAME)) {
            return self::NOT_AVAILABLE;
        }

        $integration = $this;

        // This happens somewhat early in the setup, though there may be a better candidate
        \DDTrace\hook_method('yii\di\Container', '__construct', null, function () use ($integration) {
            if (Versions::versionMatches('2.0', \Yii::getVersion())) {
                $integration->loadV2();
            }
        });

        return self::LOADED;
    }

    public function loadV2()
    {
        $rootSpan = \DDTrace\root_span();
        if (!$rootSpan) {
            return Integration::NOT_LOADED;
        }

        $rootSpan->meta[Tag::COMPONENT] = YiiIntegration::NAME;
        $rootSpan->meta[Tag::SPAN_KIND] = 'server';

        $this->addTraceAnalyticsIfEnabled($rootSpan);
        $service = \ddtrace_config_app_name(YiiIntegration::NAME);

        \DDTrace\trace_method(
            'yii\web\Application',
            'run',
            function (SpanData $span) use ($service) {
                $span->name = $span->resource = \get_class($this) . '.run';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->meta[Tag::COMPONENT] = YiiIntegration::NAME;
            }
        );

        // We assume the first controller is the one to assign to app.endpoint
        $firstController = null;
        \DDTrace\hook_method(
            'yii\web\Application',
            'createController',
            null,
            function ($app, $appClass, $args, $retval) use (&$firstController) {
                if ($firstController === null && isset($args[0], $retval) && \is_array($retval) && !empty($retval)) {
                    $firstController = $retval[0];
                }
            }
        );

        /* Note that Applications are Modules, so this will also trace Application::runAction, but
         * modules are worth tracing independently, as multiple modules can trigger per
         * application, such as in the event of an unhandled exception.
         */
        \DDTrace\trace_method(
            'yii\base\Module',
            'runAction',
            function (SpanData $span, $args) use ($service) {
                $span->name = \get_class($this) . '.runAction';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->resource = YiiIntegration::extractResourceNameFromRunAction($args) ?: $span->name;
                $span->meta[Tag::COMPONENT] = YiiIntegration::NAME;
            }
        );

        \DDTrace\trace_method(
            'yii\base\Controller',
            'runAction',
            function (SpanData $span, $args) use (&$firstController, $service, $rootSpan) {
                $span->name = \get_class($this) . '.runAction';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->resource = YiiIntegration::extractResourceNameFromRunAction($args) ?: $span->name;
                $span->meta[Tag::COMPONENT] = YiiIntegration::NAME;

                if (
                    $firstController === $this
                    && empty($rootSpan->meta['app.endpoint'])
                    && isset($this->action->actionMethod)
                ) {
                    $controller = \get_class($this);
                    $endpoint = "{$controller}::{$this->action->actionMethod}";
                    $rootSpan->meta["app.endpoint"] = $endpoint;
                    $rootSpan->meta[Tag::HTTP_URL] =
                        \DDTrace\Util\Normalizer::urlSanitize(Url::base(true) . Url::current());
                }

                if (empty($rootSpan->meta['app.route.path'])) {
                    $route = $this->module->requestedRoute;
                    $namedParams = [$route];
                    $placeholders = [$route];
                    $placeholder = '__dd_route_param';
                    if (isset($args[1]) && \is_array($args[1]) && !empty($args[1])) {
                        foreach ($args[1] as $param => $unused) {
                            $namedParams[$param] = ":{$param}";
                            $placeholders[$param] = $placeholder;
                        }
                    }

                    $routePath = \DDTrace\Util\Normalizer::urlSanitize(\urldecode(Url::toRoute($namedParams)));
                    $rootSpan->meta['app.route.path'] = $routePath;

                    $resourceName = \str_replace(
                        $placeholder,
                        '?',
                        \DDTrace\Util\Normalizer::urlSanitize(\urldecode(Url::toRoute($placeholders)))
                    );
                    $rootSpan->resource = "{$_SERVER['REQUEST_METHOD']} {$resourceName}";
                }
            }
        );

        \DDTrace\trace_method(
            'yii\base\View',
            'renderFile',
            function (SpanData $span, $args) use ($service) {
                $span->name = \get_class($this) . '.renderFile';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->resource = isset($args[0]) && \is_string($args[0]) ? $args[0] : $span->name;
                $span->meta[Tag::COMPONENT] = YiiIntegration::NAME;
            }
        );
    }

    /**
     * Returns the resource name if it can be detected from the invocation args, null otherwise.
     *
     * @param array invocation params of teh Module and Controller::runAction method.
     * @return string|null the obtained resource name or null if it cannot be detected.
     */
    public static function extractResourceNameFromRunAction($args)
    {

        if (isset($args[0]) && \is_string($args[0])) {
            // Empty action name means 'index'. See:
            //   - https://github.com/yiisoft/yii2/blob/ff0fd9a9ea043bcd915f055a868585b945399864/framework/base/Controller.php#L245
            //   - https://github.com/yiisoft/yii2/blob/ff0fd9a9ea043bcd915f055a868585b945399864/framework/base/Controller.php#L55
            return empty($args[0]) ? 'index' : $args[0];
        }

        return null;
    }
}
