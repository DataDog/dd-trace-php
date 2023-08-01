<?php

namespace DDTrace\Integrations\Drupal;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use function DDTrace\hook_method;
use function DDTrace\trace_method;

class DrupalIntegration extends Integration
{
    const NAME = 'drupal';

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return self::NAME;
    }

    public function init()
    {
        trace_method(
            'Drupal\Core\DrupalKernel',
            'handle',
            [
                'prehook' => function (SpanData $span) {
                    $service = \ddtrace_config_app_name('drupal');

                    $rootSpan = \DDTrace\root_span();
                    $rootSpan->name = 'drupal.request';
                    $rootSpan->service = $service;
                    $rootSpan->meta[Tag::SPAN_KIND] = 'server';
                    $rootSpan->meta[Tag::COMPONENT] = DrupalIntegration::NAME;

                    $span->name = 'drupal.kernel.handle';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $service;
                    $span->meta[Tag::SPAN_KIND] = 'server';
                    $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;
                }
            ]
        );

        trace_method(
            'Drupal\Core\DrupalKernel',
            'boot',
            [
                'prehook' => function (SpanData $span) {
                    $span->name = 'drupal.kernel.boot';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = \ddtrace_config_app_name('drupal');
                    $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;
                }
            ]
        );

        hook_method(
            'Drupal\Core\Controller\ControllerResolver',
            'getControllerFromDefinition',
            null,
            function ($This, $scope, $args, $retval) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan === null) {
                    return;
                }

                if (is_array($retval)) {
                    list($controller, $method) = $retval;
                    $rootSpan->resource = $controller . '::' . $method;
                } else {
                    $rootSpan->resource = $retval;
                }
            }
        );

        trace_method(
            'Drupal\Core\Render\Renderer',
            'render',
            function (SpanData $span) {
                $span->name = 'drupal.render';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('drupal');
                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;
            }
        );


        return Integration::LOADED;
    }
}