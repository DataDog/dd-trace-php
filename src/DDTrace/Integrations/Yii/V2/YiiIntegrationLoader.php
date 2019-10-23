<?php

namespace DDTrace\Integrations\Yii\V2;

use DDTrace\Configuration;
use DDTrace\Contracts\Scope;
use DDTrace\GlobalTracer;
use DDTrace\Http\Urls;
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
        $service = Configuration::get()->appName(YiiSandboxedIntegration::NAME);

        // This will also attach app.endpoint info to the root span
        \dd_trace_method(
            'yii\web\Application',
            'run',
            function (SpanData $span) use ($root, $service) {
                $span->name = $span->resource = \get_class($this) . '.run';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;

                if (isset($this->controller->action->actionMethod)) {
                    $controller = \get_class($this->controller);
                    $endpoint = "{$controller}::{$this->controller->action->actionMethod}";
                    $root->setTag("app.endpoint", $endpoint);
                    $root->setTag(Tag::HTTP_URL, Url::base(true) . Url::current());
                }
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
            function (SpanData $span, $args) use ($service) {
                $span->name = \get_class($this) . '.runAction';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->resource = isset($args[0]) && \is_string($args[0]) ? $args[0] : $span->name;
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

        /* I'm hoping to be able to get app.route.path and proper root resource name out of this somehow:
        \dd_trace_method('yii\web\Request', 'resolve', function (SpanData $span, $args, $retval, $ex) use ($service) {
            $span->name = $span->resource = \get_class($this) . '.resolve';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;

            $pathInfo = $this->getPathInfo();

            if (!$ex) {
                list($route, $params) = $retval;
            }
            return false;
        });
        */

        $root->setTag(Tag::SERVICE_NAME, $service);
        if ('cli' !== PHP_SAPI) {
            $normalizer = new Urls(explode(',', getenv('DD_TRACE_RESOURCE_URI_MAPPING')));
            $root->setTag(
                Tag::RESOURCE_NAME,
                $_SERVER['REQUEST_METHOD'] . ' ' . $normalizer->normalize($_SERVER['REQUEST_URI']),
                true
            );
        }

        return Integration::LOADED;
    }
}
