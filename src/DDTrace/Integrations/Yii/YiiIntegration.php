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
     * {@inheritdoc}
     */
    public static function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public static function init(): int
    {
        if (!Versions::versionMatches('2.0', \Yii::getVersion())) {
            return self::NOT_AVAILABLE;
        }

        \DDTrace\hook_method(
            'yii\di\Container',
            '__construct',
            function () {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan !== null) {
                    $rootSpan->meta[Tag::COMPONENT] = YiiIntegration::NAME;
                    $rootSpan->meta[Tag::SPAN_KIND] = 'server';
                    YiiIntegration::addTraceAnalyticsIfEnabled($rootSpan);
                }
            }
        );

        \DDTrace\trace_method(
            'yii\web\Application',
            'run',
            function (SpanData $span) {
                $span->name = $span->resource = \get_class($this) . '.run';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name(YiiIntegration::NAME);
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
            function (SpanData $span, $args) {
                $span->name = \get_class($this) . '.runAction';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name(YiiIntegration::NAME);
                $span->resource = YiiIntegration::extractResourceNameFromRunAction($args) ?: $span->name;
                $span->meta[Tag::COMPONENT] = YiiIntegration::NAME;
            }
        );

        \DDTrace\trace_method(
            'yii\base\Controller',
            'runAction',
            function (SpanData $span, $args) use (&$firstController) {
                $span->name = \get_class($this) . '.runAction';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name(YiiIntegration::NAME);
                $span->resource = YiiIntegration::extractResourceNameFromRunAction($args) ?: $span->name;
                $span->meta[Tag::COMPONENT] = YiiIntegration::NAME;

                $rootSpan = \DDTrace\root_span();

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
                    $placeholder = '__dd_route_param';

                    // When controllers are directly set in the app,
                    // the controller falls back to the app and module is set
                    // But when the controller is in a module, the module property doesn't exist
                    // and we need to fallback on actionParams

                    if (
                        !empty($this->module)
                        && \property_exists($this->module, 'requestedRoute')
                        && !empty($this->module->requestedRoute)
                    ) {
                        $route = $this->module->requestedRoute;
                        $namedParams = [$route];
                        $placeholders = [$route];
                        if (isset($args[1]) && \is_array($args[1]) && !empty($args[1])) {
                            foreach ($args[1] as $param => $unused) {
                                $namedParams[$param] = ":{$param}";
                                $placeholders[$param] = $placeholder;
                            }
                        }
                    } elseif (\property_exists($this, 'actionParams')) {
                        $actionParams = $this->actionParams;
                        $placeholders = $this->actionParams;
                        if (isset($args[1]) && \is_array($args[1]) && !empty($args[1])) {
                            foreach ($args[1] as $param => $unused) {
                                if (!empty($actionParams[$param])) {
                                    $actionParams[$param] = ":{$param}";
                                    $placeholders[$param] = $placeholder;
                                }
                            }
                        }

                        $namedParams = [implode('/', $actionParams)];
                        $placeholders = [implode('/', $placeholders)];
                    } else {
                        return;
                    }

                    $routePath = \DDTrace\Util\Normalizer::urlSanitize(
                        \urldecode(Url::toRoute($namedParams)),
                        false,
                        true
                    );

                    $rootSpan->meta['app.route.path'] = $routePath;
                    $rootSpan->meta[Tag::HTTP_ROUTE] = $routePath;

                    if (dd_trace_env_config("DD_HTTP_SERVER_ROUTE_BASED_NAMING")) {
                        $resourceName = \str_replace(
                            $placeholder,
                            '?',
                            \DDTrace\Util\Normalizer::urlSanitize(\urldecode(Url::toRoute($placeholders)), false, true)
                        );
                        $rootSpan->resource = "{$_SERVER['REQUEST_METHOD']} {$resourceName}";
                    }
                }
            }
        );

        \DDTrace\trace_method(
            'yii\base\View',
            'renderFile',
            function (SpanData $span, $args) {
                $span->name = \get_class($this) . '.renderFile';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name(YiiIntegration::NAME);
                $span->resource = isset($args[0]) && \is_string($args[0]) ? $args[0] : $span->name;
                $span->meta[Tag::COMPONENT] = YiiIntegration::NAME;
            }
        );

        return self::LOADED;
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
