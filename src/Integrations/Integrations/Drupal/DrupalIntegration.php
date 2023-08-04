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
use function DDTrace\trace_function;
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

        /* Noisy?
        trace_method(
            'Drupal\Core\Render\Renderer',
            'render',
            [
                'recurse' => true,
                'posthook' =>  function (SpanData $span, $args) {
                    $span->name = 'drupal.render';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = \ddtrace_config_app_name('drupal');
                    $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;
                }
            ]
        );
        */

        trace_method(
            'Drupal\views\ViewExecutable',
            'execute',
            function (SpanData $span, $args, $retval) {
                // TODO: Do view metrics
                $span->name = 'drupal.view.execute';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('drupal');
                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;
                if (method_exists($this->storage, 'label')) {
                    $label = $this->storage->label();
                    if (is_string($label)) {
                        $span->meta['drupal.view'] = $label;
                    }
                }
            }
        );

        hook_method(
            'Drupal\page_cache\StackMiddleware\PageCache',
            'get',
            function ($pageCache, $scope, $args, $retval) {
                $rootSpan = \DDTrace\root_span();
                if ($rootSpan === null) {
                    return;
                }

                if ($retval) {
                    $rootSpan->meta['drupal.page_cache'] = 'hit';
                }
            }
        );

        hook_method(
            'Drupal\Core\Extension\ModuleHandler',
            'invokeAllWith',
            function ($moduleHandler, $scope, $args) {
                $hook = $args[0];
                // TODO: hook metrics

                $callback = $args[1];
                $modules = $moduleHandler->getImplementationInfo($hook);
                foreach ($modules as $module) {
                    install_hook(
                        $callback,
                        function (HookData $hook) use ($module) {
                            // TODO: module metrics

                            remove_hook($hook->id);
                        }
                    );
                }
            }
        );

        trace_method(
            'Drupal\Core\Extension\ModuleHandler',
            'invoke',
            function (SpanData $span, $args) {
                $hook = $args[0];
                $module = $args[1];

                $span->name = 'drupal.hook.invoke';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('drupal');
                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;

                $span->meta['drupal.hook'] = $hook;
                $span->meta['drupal.module'] = $module;

                $callback = $module . '_' . $hook;
                // TODO: install_hook on callback + metrics
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

        $themeEngines = [];
        hook_method(
            'Drupal\Core\Theme\ThemeManager',
            'render',
            function ($themeManager, $scope, $args) use (&$themeEngines) {
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

                if (!isset($themeEngines[$themeEngine])) {
                    $themeEngines[$themeEngine] = true;
                    Logger::get()->debug("ThemeManager::render install_hook on $renderFunction");
                    trace_function(
                        $renderFunction,
                        [
                            'recurse' => true,
                            'posthook' =>  function (SpanData $span, $args) use ($themeEngine) {
                                $span->name = 'drupal.templating.render';
                                $span->service = \ddtrace_config_app_name('drupal');
                                $span->type = Type::WEB_SERVLET;
                                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;

                                $templateFile = $args[0];
                                if (isset($templateFile)) {
                                    $span->meta['drupal.template'] = $templateFile;
                                    $span->meta['drupal.theme_engine'] = $themeEngine;
                                }
                            }
                        ]
                    );
                } else {
                    Logger::get()->debug("ThemeManager::render hook already installed on $renderFunction");
                }
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