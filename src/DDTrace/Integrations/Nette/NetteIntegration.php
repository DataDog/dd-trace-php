<?php

namespace DDTrace\Integrations\Nette;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

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
    public function init(): int
    {
        $service = \ddtrace_config_app_name(NetteIntegration::NAME);

        $integration = $this;
        $setRootSpanFn = function () use ($service, $integration) {
            $rootSpan = \DDTrace\root_span();
            if ($rootSpan === null) {
                return;
            }

            $rootSpan->meta[Tag::SPAN_KIND] = 'server';

            $integration->addTraceAnalyticsIfEnabled($rootSpan);
            $rootSpan->service = $service;
            $rootSpan->meta[Tag::COMPONENT] = NetteIntegration::NAME;
        };

        \DDTrace\hook_method('Nette\Configurator', '__construct', $setRootSpanFn);
        \DDTrace\hook_method('Nette\Bootstrap\Configurator', '__construct', $setRootSpanFn);


        \DDTrace\trace_method(
            'Nette\Configurator',
            'createRobotLoader',
            function (SpanData $span) use ($service) {
                $span->name = 'nette.configurator.createRobotLoader';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->meta[Tag::COMPONENT] = NetteIntegration::NAME;
            }
        );

        \DDTrace\trace_method(
            'Nette\Application\Application',
            'run',
            function (SpanData $span) use ($service) {
                $span->name = 'nette.application.run';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->meta[Tag::COMPONENT] = NetteIntegration::NAME;

                $rootSpan = \DDTrace\root_span();
                $rootSpan->meta[Tag::HTTP_STATUS_CODE] = http_response_code();
            }
        );

        \DDTrace\trace_method(
            'Nette\Application\UI\Presenter',
            'run',
            function (SpanData $span, $args) use ($service) {
                $span->name = 'nette.presenter.run';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->meta[Tag::COMPONENT] = NetteIntegration::NAME;

                if (count($args) < 1 || !\is_a($args[0], '\Nette\Application\Request')) {
                    return;
                }

                $request = $args[0];
                $presenter = $request->getPresenterName();
                $action = $request->getParameter('action');

                $rootSpan = \DDTrace\root_span();
                $rootSpan->meta[Tag::HTTP_METHOD] = $request->getMethod();
                $rootSpan->meta['nette.route.presenter'] = $presenter;
                $rootSpan->meta['nette.route.action'] = $action;
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
                $span->meta[Tag::COMPONENT] = NetteIntegration::NAME;

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
                $span->meta[Tag::COMPONENT] = NetteIntegration::NAME;

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
                $span->meta[Tag::COMPONENT] = NetteIntegration::NAME;

                if (count($args) >= 1) {
                    $span->meta['nette.latte.templateName'] = $args[0];
                }
            }
        );

        return self::LOADED;
    }
}
