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
    public static function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public static function init(): int
    {
        $service = \ddtrace_config_app_name(self::NAME);

        $setRootSpanFn = static function () use ($service) {
            $rootSpan = \DDTrace\root_span();
            if ($rootSpan === null) {
                return;
            }

            $rootSpan->meta[Tag::SPAN_KIND] = 'server';

            self::addTraceAnalyticsIfEnabled($rootSpan);
            $rootSpan->service = $service;
            $rootSpan->meta[Tag::COMPONENT] = self::NAME;
        };

        \DDTrace\hook_method('Nette\Configurator', '__construct', $setRootSpanFn);
        \DDTrace\hook_method('Nette\Bootstrap\Configurator', '__construct', $setRootSpanFn);


        \DDTrace\trace_method(
            'Nette\Configurator',
            'createRobotLoader',
            static function (SpanData $span) use ($service) {
                $span->name = 'nette.configurator.createRobotLoader';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->meta[Tag::COMPONENT] = self::NAME;
            }
        );

        \DDTrace\trace_method(
            'Nette\Application\Application',
            'run',
            static function (SpanData $span) use ($service) {
                $span->name = 'nette.application.run';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->meta[Tag::COMPONENT] = self::NAME;

                $rootSpan = \DDTrace\root_span();
                $rootSpan->meta[Tag::HTTP_STATUS_CODE] = http_response_code();
            }
        );

        \DDTrace\trace_method(
            'Nette\Application\UI\Presenter',
            'run',
            static function (SpanData $span, $args) use ($service) {
                $span->name = 'nette.presenter.run';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->meta[Tag::COMPONENT] = self::NAME;

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
            static function (SpanData $span, $args) use ($service) {
                $span->name = 'nette.latte.createTemplate';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->meta[Tag::COMPONENT] = self::NAME;

                if (count($args) >= 1) {
                    $span->meta['nette.latte.templateName'] = $args[0];
                }
            }
        );

        \DDTrace\trace_method(
            'Latte\Engine',
            'render',
            static function (SpanData $span, $args) use ($service) {
                $span->name = 'nette.latte.render';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->meta[Tag::COMPONENT] = self::NAME;

                if (count($args) >= 1) {
                    $span->meta['nette.latte.templateName'] = $args[0];
                }
            }
        );

        \DDTrace\trace_method(
            'Latte\Engine',
            'renderToString',
            static function (SpanData $span, $args) use ($service) {
                $span->name = 'nette.latte.render';
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->meta[Tag::COMPONENT] = self::NAME;

                if (count($args) >= 1) {
                    $span->meta['nette.latte.templateName'] = $args[0];
                }
            }
        );

        return self::LOADED;
    }
}
