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

        // Views Metrics
        trace_method(
            'Drupal\views\ViewExecutable',
            'execute',
            function (SpanData $span, $args, $retval) {
                // TODO: Create a new span as a metric workaround
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

        // Cache Metrics (Cache hit/miss)
        /*
        hook_method(
            'Drupal\Core\Cache\CacheBackendInterface',
            'getMultiple',
            function ($cacheBackend, $scope, $args) {
                $nCids = count($args[0]);
            },
            function ($cacheBackend, $scope, $args, $retval) {
                $nCids = count($args[0]); // &cids parameter is modified by reference to match the cache hits
            }
        );
        */

        // Module and Hook Metrics
        hook_method(
            'Drupal\Core\Extension\ModuleHandler',
            'invokeAllWith',
            function ($moduleHandler, $scope, $args) {
                /** @var string $hook */
                $hook = $args[0];
                /** @var callable $callback */
                $callback = $args[1];

                install_hook(
                    $callback,
                    function (HookData $callbackHookData) use ($hook) {
                        // callback's signature: (callable $hook, string $module)
                        $args = $callbackHookData->args;
                        $module = $args[1];

                        $functionName = $module . '_' . $hook;
                        install_hook(
                            $functionName,
                            function (HookData $fnHookData) use ($hook, $module, $functionName) {
                                // TODO: Create a span as a metric workaround
                                $span = $fnHookData->span();
                                $span->name = 'drupal.hook.' . $hook;
                                $span->type = Type::WEB_SERVLET;
                                $span->service = \ddtrace_config_app_name('drupal');
                                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;

                                $span->meta['drupal.hook'] = $hook;
                                $span->meta['drupal.module'] = $module;
                                $span->meta['drupal.function'] = $functionName;

                                remove_hook($fnHookData->id);
                            }
                        );

                        remove_hook($callbackHookData->id);
                    }
                );
            }
        );

        install_hook(
            'Drupal\views\ViewExecutable::execute',
            function (HookData $hook) {
                // TODO: Create a span as a metric workaround
                $span = $hook->span();
                $span->name = 'drupal.view.execute';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('drupal');
                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;

                /** @var \Drupal\views\Entity\View $storage */
                $storage = $this->storage;

                if (method_exists($storage, 'label')) {
                    $label = $storage->label();
                    if (is_string($label)) {
                        $span->meta['drupal.view'] = $label;
                    }
                }
            }
        );


        /*
        trace_method(
            'Drupal\big_pipe\Render\BigPipe',
            'renderPlaceholder',
            function (SpanData $span, $args) {
                $span->name = 'drupal.placeholder.render';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('drupal');
                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;
            }
        );
        */

        $themeEngines = [];
        hook_method(
            'Drupal\Core\Theme\ThemeManager',
            'render',
            function ($themeManager, $scope, $args) use (&$themeEngines) {
                $renderFunction = 'twig_render_template'; // Default

                $activeTheme = $themeManager->getActiveTheme();
                $themeName = $activeTheme->getName();
                Logger::get()->debug("ThemeManager::render themeName: $themeName");
                $themeEngine = $activeTheme->getEngine();
                Logger::get()->debug("ThemeManager::render themeEngine: $themeEngine");
                // The theme engine may use a different extension and a different renderer
                if (isset($themeEngine) && function_exists("{$themeEngine}_render_template")) {
                    $renderFunction = "{$themeEngine}_render_template";
                } else {
                    $themeEngine = 'twig'; // Default
                }

                if (!isset($themeEngines[$themeEngine])) { // Install hook only once per theme engine
                    $themeEngines[$themeEngine] = true;
                    Logger::get()->debug("ThemeManager::render install_hook on $renderFunction");
                    trace_function(
                        $renderFunction,
                        [
                            'recurse' => true,
                            'posthook' =>  function (SpanData $span, $args) use ($themeName, $themeEngine) {
                                $span->name = 'drupal.template.render';
                                $span->service = \ddtrace_config_app_name('drupal');
                                $span->type = Type::WEB_SERVLET;
                                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;

                                $templateFile = $args[0];
                                if (isset($templateFile)) {
                                    $span->meta['drupal.template'] = $templateFile;
                                    $span->meta['drupal.theme_name'] = $themeName;
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