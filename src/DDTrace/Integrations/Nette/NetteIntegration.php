<?php

namespace DDTrace\Integrations\Nette;

use DDTrace\Contracts\Scope;
use DDTrace\GlobalTracer;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use Nette\Application\Application;

class NetteIntegration extends Integration
{
    const NAME = 'nette';

    /**
     * {@inheritdoc}
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

        if (\PHP_MAJOR_VERSION < 7) {
            $integration = $this;
            \DDTrace\hook_method('Nette\Configurator', '__construct', function () use ($integration) {
                $integration->load();
            });
        } else {
            $this->load();
        }

        return self::LOADED;
    }

    public function load()
    {
        $scope = GlobalTracer::get()->getRootScope();
        if (!$scope instanceof Scope) {
            return;
        }

        $service = \ddtrace_config_app_name(NetteIntegration::NAME);

        $root = $scope->getSpan();
        $root->setTraceAnalyticsCandidate();
        $root->setTag(Tag::SERVICE_NAME, $service);

        \DDTrace\trace_method(
            'Nette\Configurator',
            'createRobotLoader',
            function (SpanData $span) use ($service) {
                $span->name = 'nette.configurator.createRobotLoader';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
            }
        );

        \DDTrace\trace_method(
            'Nette\Application\Application',
            'run',
            function (SpanData $span) use ($root, $service) {
                $span->name = 'nette.application.run';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $root->setTag(Tag::HTTP_STATUS_CODE, http_response_code());
            }
        );

        \DDTrace\trace_method(
            'Nette\Application\UI\Presenter',
            'run',
            function (SpanData $span, $args) use ($root, $service) {

                $span->name = 'nette.presenter.run';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;

                if (count($args) < 1 || !\is_a($args[0], '\Nette\Application\Request')) {
                    return;
                }

                $request = $args[0];
                $presenter = $request->getPresenterName();
                $action = $request->getParameter('action');

                $root->setTag(Tag::HTTP_METHOD, $request->getMethod());
                $root->setTag('nette.route.presenter', $presenter);
                $root->setTag('nette.route.action', $action);
            }
        );

        // Latte template engine traces
        \DDTrace\trace_method(
            'Latte\Engine',
            'createTemplate',
            function (SpanData $span, $args) use ($service) {
                $span->name = 'nette.latte.createTemplate';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;

                if (count($args) >= 1) {
                    $span->meta['nette.latte.templateName'] = $args[0];
                }
            }
        );

        \DDTrace\trace_method(
            'Latte\Engine',
            'render',
            function (SpanData $span, $args) use ($service) {
                $span->name = 'nette.latte.render';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;

                if (count($args) >= 1) {
                    $span->meta['nette.latte.templateName'] = $args[0];
                }
            }
        );

        \DDTrace\trace_method(
            'Latte\Engine',
            'renderToString',
            function (SpanData $span, $args) use ($service) {
                $span->name = 'nette.latte.render';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;

                if (count($args) >= 1) {
                    $span->meta['nette.latte.templateName'] = $args[0];
                }
            }
        );
    }
}
