<?php

namespace DDTrace\Integrations\Drupal;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use function DDTrace\hook_method;
use function DDTrace\install_hook;
use function DDTrace\remove_hook;
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

                /*
                if (is_array($retval)) {
                    list($controller, $method) = $retval;
                    $rootSpan->resource = get_class($controller) . '::' . $method;
                } elseif (is_object($retval)) {
                    $rootSpan->resource = get_class($retval);
                } elseif (is_string($retval)) {
                    $rootSpan->resource = $retval;
                }
                */
            }
        );

        trace_method(
            'Drupal\Core\Render\Renderer',
            'render',
            function (SpanData $span, $args) {
                $span->name = 'drupal.render';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('drupal');
                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;

                // TODO: Add tags ?
            }
        );

        /*
        trace_method(
            'Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher',
            'dispatch',
            [
                'recurse' => true,
                'prehook' => function (SpanData $span, $args) {
                    if (!isset($args[0])) {
                        return false;
                    }
                    if (\is_object($args[0])) {
                        // dispatch($event, string $eventName = null)
                        $event = $args[0];
                        $eventName = isset($args[1]) && \is_string($args[1]) ? $args[1] : \get_class($event);
                    } elseif (\is_string($args[0])) {
                        // dispatch($eventName, Event $event = null)
                        $eventName = $args[0];
                        $event = isset($args[1]) && \is_object($args[1]) ? $args[1] : null;
                    } else {
                        // Invalid API usage
                        return false;
                    }

                    // trace the container itself
                    if ($eventName === 'kernel.controller' && \method_exists($event, 'getController')) {
                        $controller = $event->getController();
                        if (!($controller instanceof \Closure)) {
                            if (\is_callable($controller, false, $controllerName) && $controllerName !== null) {
                                if (\strpos($controllerName, '::') > 0) {
                                    list($class, $method) = \explode('::', $controllerName);
                                    if (isset($class, $method)) {
                                        \DDTrace\trace_method(
                                            $class,
                                            $method,
                                            function (SpanData $span) use ($controllerName) {
                                                $span->name = 'drupal.controller';
                                                $span->resource = $controllerName;
                                                $span->type = Type::WEB_SERVLET;
                                                $span->service = \ddtrace_config_app_name('drupal');
                                                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;
                                            }
                                        );
                                    }
                                } else {
                                    \DDTrace\trace_function(
                                        $controllerName,
                                        function (SpanData $span) use ($controllerName) {
                                            $span->name = 'drupal.controller';
                                            $span->resource = $controllerName;
                                            $span->type = Type::WEB_SERVLET;
                                            $span->service = \ddtrace_config_app_name('drupal');
                                            $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;
                                        }
                                    );
                                }
                            }
                        }
                    }

                    $span->name = $span->resource = 'drupal.' . $eventName;
                    $span->service = \ddtrace_config_app_name('drupal');
                    $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;
                    if ($event === null) {
                        return;
                    }
                    //if (!$injectedActionInfo) {
                    //    if ($integration->injectActionInfo($event, $eventName, $integration->symfonyRequestSpan)) {
                    //        $injectedActionInfo = true;
                    //    }
                    //}
                }
            ]
        );
        */

        hook_method(
            'Drupal\Core\Theme\ThemeManager',
            'render',
            function ($themeManager, $scope, $args) {
                $renderFunction = 'twig_render_template'; // Default

                $activeTheme = $themeManager->getActiveTheme();
                $themeEngine = $activeTheme->getEngine();
                Logger::get()->debug("ThemeManager::render themeEngine: $themeEngine");
                // The theme engine may use a different extension and a different renderer
                if (isset($themeEngine) && function_exists("{$themeEngine}_render_template")) {
                    $renderFunction = "{$themeEngine}_render_template";
                } else {
                    $themeEngine = 'twig'; // Default
                }

                install_hook(
                    $renderFunction,
                    function (HookData $hook) use ($themeEngine) {
                        $span = $hook->span();
                        $args = $hook->args;

                        $span->name = 'drupal.templating.render';
                        $span->service = \ddtrace_config_app_name('drupal');
                        $span->type = Type::WEB_SERVLET;
                        $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;

                        $templateFile = $args[0];
                        if (isset($templateFile)) {
                            $span->meta['drupal.template'] = $templateFile;
                            $span->meta['drupal.theme_engine'] = $themeEngine;
                        }

                        remove_hook($hook->id);
                    }
                );
            }
        );

        /*
        hook_method(
            'Drupal\Core\Utility\LinkGenerator',
            'generate',
            function ($linkGenerator, $scope, $args, $generatedLink) {
                if ($generatedLink !== null && $generatedLink instanceof \Drupal\Core\GeneratedLink) {
                    $url = $generatedLink->getGeneratedLink();
                }
            }
        );
        */

        trace_method(
            'Drupal\big_pipe\Render\BigPipeResponse',
            'sendContent',
            function (SpanData $span) {
                $span->name = 'drupal.response.send';
                $span->service = \ddtrace_config_app_name('drupal');
                $span->type = Type::WEB_SERVLET;
                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;
            }
        );


        return Integration::LOADED;
    }
}