<?php

namespace DDTrace\Integrations\Yii\V2;

use DDTrace\Configuration;
use DDTrace\Contracts\Scope;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\Yii\YiiSandboxedIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use yii\helpers\Url;

class YiiIntegrationLoader
{

    public function load(YiiSandboxedIntegration $integration)
    {
        $scope = GlobalTracer::get()->getRootScope();
        if (!$scope instanceof Scope) {
            return Integration::NOT_LOADED;
        }
        $root = $scope->getSpan();
        // Overwrite the default web integration
        $root->setIntegration($integration);
        $root->setTraceAnalyticsCandidate();
        $service = \ddtrace_config_app_name(YiiSandboxedIntegration::NAME);

        \dd_trace_method(
            'yii\web\Application',
            'run',
            function (SpanData $span) use ($service) {
                $span->name = $span->resource = \get_class($this) . '.run';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
            }
        );

        // We assume the first controller is the one to assign to app.endpoint
        $firstController = null;
        \dd_trace_method(
            'yii\web\Application',
            'createController',
            function (SpanData $span, $args, $retval, $ex) use (&$firstController) {
                if (!$ex && isset($args[0], $retval) && \is_array($retval) && !empty($retval)) {
                    if ($firstController === null) {
                        $firstController = $retval[0];
                    }
                }
                return false;
            }
        );

        /* Note that Applications are Modules, so this will also trace Application::runAction, but
         * modules are worth tracing independently, as multiple modules can trigger per
         * application, such as in the event of an unhandled exception.
         */
        \dd_trace_method(
            'yii\base\Module',
            'runAction',
            function (SpanData $span, $args) use ($service) {
                $span->name = \get_class($this) . '.runAction';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->resource = isset($args[0]) && \is_string($args[0]) ? $args[0] : $span->name;
            }
        );

        \dd_trace_method(
            'yii\base\Controller',
            'runAction',
            function (SpanData $span, $args) use (&$firstController, $service, $root) {
                $span->name = \get_class($this) . '.runAction';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->resource = isset($args[0]) && \is_string($args[0]) ? $args[0] : $span->name;

                if (
                    $firstController === $this
                    && $root->getTag('app.endpoint') === null
                    && isset($this->action->actionMethod)
                ) {
                    $controller = \get_class($this);
                    $endpoint = "{$controller}::{$this->action->actionMethod}";
                    $root->setTag("app.endpoint", $endpoint);
                    $root->setTag(Tag::HTTP_URL, Url::base(true) . Url::current());
                }

                if ($root->getTag('app.route.path') === null) {
                    $route = $this->module->requestedRoute;
                    $namedParams = [$route];
                    $placeholders = [$route];
                    if (isset($args[1]) && \is_array($args[1]) && !empty($args[1])) {
                        foreach ($args[1] as $param => $unused) {
                            $namedParams[$param] = ":{$param}";
                            $placeholders[$param] = '?';
                        }
                    }

                    $routePath = \urldecode(Url::toRoute($namedParams));
                    $root->setTag('app.route.path', $routePath);

                    $resourceName = \urldecode(Url::toRoute($placeholders));
                    $root->setTag(Tag::RESOURCE_NAME, "{$_SERVER['REQUEST_METHOD']} {$resourceName}", true);
                }
            }
        );

        \dd_trace_method(
            'yii\base\View',
            'renderFile',
            function (SpanData $span, $args) use ($service) {
                $span->name = \get_class($this) . '.renderFile';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->resource = isset($args[0]) && \is_string($args[0]) ? $args[0] : $span->name;
            }
        );

        return Integration::LOADED;
    }
}
