<?php

namespace DDTrace\Integrations\Drupal;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use function DDTrace\hook_method;
use function DDTrace\install_hook;
use function DDTrace\remove_hook;
use function DDTrace\set_user;
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
        ini_set('datadog.trace.spans_limit', max(1500, ini_get('datadog.trace.spans_limit')));

        trace_method(
            'Drupal\Core\DrupalKernel',
            'handle',
            [
                'prehook' => function (SpanData $span) {
                    // A pre-hook is used here to ensure that the root span name is set before the symfony integration
                    // checks for it.
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

        $stackedHttpKernelTracer = function (SpanData $span) {
            $span->name = 'drupal.httpkernel.handle';
            $span->type = Type::WEB_SERVLET;
            $span->service = \ddtrace_config_app_name('drupal');
            $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;
        };
        // See Drupal\Core\DependencyInjection\Compiler\StackedKernelPass
        // If a middleware is tagged with 'responder' => true, then the underlying middleware and the HTTP kernel
        // are flagged as 'lazy', meaning Symfony\Component\HttpKernel\HttpKernel::handle() may not be called.
        // Drupal 9-
        trace_method('Stack\StackedHttpKernel', 'handle', $stackedHttpKernelTracer);
        // Drupal 10+
        trace_method('Drupal\Core\StackMiddleware\StackedHttpKernel', 'handle', $stackedHttpKernelTracer);

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

        // Cache Metrics (Cache hit/miss) - TBC
        /*
        hook_method(
            'Drupal\Core\Cache\CacheBackendInterface',
            'getMultiple',
            function ($cacheBackend, $scope, $args) {
                $candidates = count($args[0]);
            },
            function ($cacheBackend, $scope, $args, $retval) {
                $hits = count($args[0]); // the &cids parameter is modified by reference to match the cache hits
            }
        );
        */

        // Module and Hook Metrics
        /*
        hook_method(
            'Drupal\Core\Extension\ModuleHandler',
            'invokeAllWith',
            function ($moduleHandler, $scope, $args) {
                // @var string $hook
                $hook = $args[0];
                // @var callable $callback
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
                                // Create a span as a metric workaround
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
        */

        // View Metrics
        /*
        install_hook(
            'Drupal\views\ViewExecutable::execute',
            function (HookData $hook) {
                // Create a span as a metric workaround
                $span = $hook->span();
                $span->name = 'drupal.view.execute';
                $span->type = Type::WEB_SERVLET;
                $span->service = \ddtrace_config_app_name('drupal');
                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;

                // @var \Drupal\views\Entity\View $storage
                $storage = $this->storage;

                if (method_exists($storage, 'label')) {
                    $label = $storage->label();
                    if (is_string($label)) {
                        $span->meta['drupal.view'] = $label;
                    }
                }
            }
        );
        */

        trace_method(
            'Drupal\Core\Theme\ThemeManager',
            'render',
            [
                'recurse' => true,
                'prehook' => function (SpanData $span) {
                    $span->name = 'drupal.theme.render';
                    $span->service = \ddtrace_config_app_name('drupal');
                    $span->type = Type::WEB_SERVLET;
                    $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;

                    /** @var \Drupal\Core\Theme\ThemeManager $themeManager */
                    $themeManager = $this;

                    $activeTheme = $themeManager->getActiveTheme();
                    $themeName = $activeTheme->getName();
                    $themeEngine = $activeTheme->getEngine();

                    if (!empty($themeName)) {
                        $span->meta['drupal.theme.name'] = $themeName;
                    }

                    if (!empty($themeEngine)) {
                        $span->meta['drupal.theme.engine'] = $themeEngine;
                        if (function_exists("{$themeEngine}_render_template")) {
                            $renderFunction = "{$themeEngine}_render_template";

                            // The theme engine may use a different extension and a different renderer
                            // Moreover, Drupal can use different themes in the same application
                            // The render function will always be called during the ThemeManager::render call
                            install_hook(
                                $renderFunction,
                                function (HookData $hook) use ($span) {
                                    $span->meta['drupal.template'] = $hook->args[0];
                                    remove_hook($hook->id);
                                }
                            );
                        }
                    }
                }
            ]
        );

        trace_method(
            'Symfony\Component\HttpFoundation\Response',
            'send',
            function (SpanData $span) {
                $span->name = 'symfony.response.send';
                $span->service = \ddtrace_config_app_name('drupal');
                $span->type = Type::WEB_SERVLET;
                $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;
            }
        );

        hook_method(
            'Drupal\Core\Session\AccountProxy',
            'setAccount',
            function ($This, $scope, $args) {
                if (!\DDTrace\root_span()) {
                    return;
                }

                $account = $args[0];
                if ($account instanceof \Drupal\Core\Session\AccountInterface) {
                    $metadata = [
                        'login' => $account->getAccountName(),
                        'username' => $account->getDisplayName(),
                        'email' => $account->getEmail(),
                    ];

                    $metadata = array_filter($metadata, function ($value) {
                        return !empty($value);
                    });

                    set_user($account->id(), $metadata);
                }
            }
        );

        return Integration::LOADED;
    }
}
