<?php

namespace DDTrace\Integrations\Drupal;

use DDTrace\HookData;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\ObjectKVStore;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use function DDTrace\active_span;
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
    public static function requiresExplicitTraceAnalyticsEnabling(): bool
    {
        return false;
    }

    public static function init(): int
    {
        ini_set('datadog.trace.spans_limit', max(1500, ini_get('datadog.trace.spans_limit')));

        trace_method(
            'Drupal\Core\DrupalKernel',
            'handle',
            [
                'prehook' => static function (SpanData $span) {
                    // A pre-hook is used here to ensure that the root span name is set before the symfony integration
                    // checks for it.
                    $service = \ddtrace_config_app_name('drupal');

                    $rootSpan = \DDTrace\root_span();
                    $rootSpan->name = 'drupal.request';
                    $rootSpan->service = $service;
                    $rootSpan->meta[Tag::SPAN_KIND] = 'server';
                    $rootSpan->meta[Tag::COMPONENT] = self::NAME;

                    $span->name = 'drupal.kernel.handle';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = $service;
                    $span->meta[Tag::SPAN_KIND] = 'server';
                    $span->meta[Tag::COMPONENT] = self::NAME;
                }
            ]
        );

        $stackedHttpKernelTracer = static function (SpanData $span) {
            $span->name = 'drupal.httpkernel.handle';
            $span->type = Type::WEB_SERVLET;
            $span->service = \ddtrace_config_app_name('drupal');
            $span->meta[Tag::COMPONENT] = self::NAME;
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
                'prehook' => static function (SpanData $span) {
                    $span->name = 'drupal.kernel.boot';
                    $span->type = Type::WEB_SERVLET;
                    $span->service = \ddtrace_config_app_name('drupal');
                    $span->meta[Tag::COMPONENT] = self::NAME;
                }
            ]
        );

        // Cache Metrics (Cache hit/miss) - TBC
        /*
        hook_method(
            'Drupal\Core\Cache\CacheBackendInterface',
            'getMultiple',
            static function ($cacheBackend, $scope, $args) {
                $candidates = count($args[0]);
            },
            static function ($cacheBackend, $scope, $args, $retval) {
                $hits = count($args[0]); // the &cids parameter is modified by reference to match the cache hits
            }
        );
        */

        // Module and Hook Metrics
        /*
        hook_method(
            'Drupal\Core\Extension\ModuleHandler',
            'invokeAllWith',
            static function ($moduleHandler, $scope, $args) {
                // @var string $hook
                $hook = $args[0];
                // @var callable $callback
                $callback = $args[1];

                install_hook(
                    $callback,
                    static function (HookData $callbackHookData) use ($hook) {
                        // callback's signature: (callable $hook, string $module)
                        $args = $callbackHookData->args;
                        $module = $args[1];

                        $functionName = $module . '_' . $hook;
                        install_hook(
                            $functionName,
                            static function (HookData $fnHookData) use ($hook, $module, $functionName) {
                                // Create a span as a metric workaround
                                $span = $fnHookData->span();
                                $span->name = 'drupal.hook.' . $hook;
                                $span->type = Type::WEB_SERVLET;
                                $span->service = \ddtrace_config_app_name('drupal');
                                $span->meta[Tag::COMPONENT] = self::NAME;

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

        install_hook(
            'Drupal\Core\Extension\ModuleHandler::invokeAllWith',
            static function (HookData $hookData) {
                /** @var string $hook */
                $hook = $hookData->args[0];
                /** @var callable $callback */
                $callback = $hookData->args[1];

                if ($hook === 'cron') {
                    $hookData->data = install_hook(
                        $callback,
                        static function (HookData $callbackHookData) use ($hook) {
                            // callback's signature: (callable $hook, string $module)
                            $args = $callbackHookData->args;
                            $module = $args[1];

                            $functionName = $module . '_' . $hook;
                            install_hook(
                                $functionName,
                                static function (HookData $fnHookData) use ($hook, $module, $functionName) {
                                    $span = $fnHookData->span();
                                    $span->name = 'drupal.hook.' . $hook;
                                    $span->type = Type::WEB_SERVLET;
                                    $span->service = \ddtrace_config_app_name('drupal');
                                    $span->meta[Tag::COMPONENT] = self::NAME;
                                    $span->resource = $functionName;

                                    $span->meta['drupal.hook'] = $hook;
                                    $span->meta['drupal.module'] = $module;
                                    $span->meta['drupal.function'] = $functionName;

                                    remove_hook($fnHookData->id);
                                }
                            );
                        }
                    );
                }
            }, static function (HookData $hookData) {
                if (isset($hookData->data)) {
                    remove_hook($hookData->data);
                }
            }
        );



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

        hook_method(
            'Drupal\Core\Theme\ThemeManager',
            'setThemeRegistry',
            static function ($themeManager, $scope, $args) {
                // The theme registry is otherwise protected
                ObjectKVStore::put($themeManager, 'theme_registry', $args[0]);
            }
        );

        hook_method(
            'Drupal\Core\Theme\Registry',
            '__construct',
            static function ($registry, $scope, $args) {
                // The theme is otherwise protected
                // _construct($root, $cache, $lock, $module_handler, $theme_handler, $theme_initialization, $theme_name, $runtime_cache, $module_list)
                ObjectKVStore::put($registry, 'theme_name', $args[6]);
            }
        );

        trace_method(
            'Drupal\Core\Theme\ThemeManager',
            'render',
            [
                'recurse' => true,
                'prehook' => function (SpanData $span, $args) {
                    $span->name = 'drupal.theme.render';
                    $span->service = \ddtrace_config_app_name('drupal');
                    $span->type = Type::WEB_SERVLET;
                    $span->meta[Tag::COMPONENT] = DrupalIntegration::NAME;

                    /** @var \Drupal\Core\Theme\ThemeManager $this */
                    $activeTheme = $this->getActiveTheme();
                    $themeName = $activeTheme->getName();
                    $themeEngine = $activeTheme->getEngine();

                    if (!empty($themeName)) {
                        $span->meta['drupal.render.theme'] = $themeName;
                    }

                    if (!empty($themeEngine)) {
                        $span->meta['drupal.render.engine'] = $themeEngine;
                        if (function_exists("{$themeEngine}_render_template")) {
                            $renderFunction = "{$themeEngine}_render_template";

                            // The theme engine may use a different extension and a different renderer
                            // Moreover, Drupal can use different themes in the same application
                            // The render function will always be called during the ThemeManager::render call
                            install_hook(
                                $renderFunction,
                                static function (HookData $hook) use ($span) {
                                    $span->meta['drupal.template.file'] = $hook->args[0];
                                    remove_hook($hook->id);
                                }
                            );
                        }
                    }
                },
                'posthook' => function (SpanData $span, $args) {
                    /** @var null|\Drupal\Core\Theme\Registry $themeRegistry */
                    $themeRegistry = ObjectKVStore::get($this, 'theme_registry');
                    if ($themeRegistry) {
                        $runtimeThemeRegistry = $themeRegistry->getRuntime();
                        $hook = $args[0];

                        if (is_array($hook)) {
                            foreach ($hook as $candidate) {
                                if ($runtimeThemeRegistry->has($candidate)) {
                                    break;
                                }
                            }
                            $hook = $candidate;
                        }

                        $originalHook = $hook;

                        if (!$runtimeThemeRegistry->has($hook)) {
                            // Iteratively strip everything after the last '__' delimiter, until an
                            // implementation is found
                            while ($pos = strrpos($hook, '__')) {
                                $hook = substr($hook, 0, $pos);
                                if ($runtimeThemeRegistry->has($hook)) {
                                    break;
                                }
                            }
                        }

                        if ($runtimeThemeRegistry->has($hook)) {
                            $span->meta['drupal.render.hook'] = $span->resource = $hook;
                            $info = $runtimeThemeRegistry->get($hook);

                            if (isset($info['base hook'])) {
                                $span->meta['drupal.render.base_hook'] = $info['base hook'];
                            }

                            if (isset($info['type'])) {
                                $span->meta['drupal.render.type'] = $info['type'];
                            }

                            if (isset($info['render element'])) {
                                $span->meta['drupal.render.element'] = $info['render element'];
                            }

                            if (isset($info['template'])) {
                                $span->meta['drupal.template.template'] = $info['template'];
                            }

                            if (isset($info['function'])) {
                                $span->meta['drupal.render.theme_function'] = $info['function'];
                            }

                            if (isset($info['path'])) {
                                // The template can be from a different theme than the active one
                                // Format: '.../themes/<theme_name>/...'
                                $path = $info['path'];
                                $themePathStart = strpos($path, '/themes/');
                                if ($themePathStart !== false) {
                                    $themePath = substr($path,  $themePathStart + 8); // Between '/themes/', 8 = strlen('/themes/')
                                    $themePath = substr($themePath, 0, strpos($themePath, '/')); // Until the next '/'
                                    $span->meta['drupal.template.theme'] = $themePath;
                                }
                            }
                        } else {
                            $span->meta['drupal.render.hook'] = $span->resource = $originalHook;
                        }
                    }
                }
            ]
        );

        hook_method(
            'Drupal\Core\EventSubscriber\MainContentViewSubscriber',
            '__construct',
            static function ($mainContViewSubscriber, $scope, $args) {
                // These are otherwise protected
                $classResolver = $args[0];
                $mainContentRenderers = $args[2];
                ObjectKVStore::put($mainContViewSubscriber, 'class_resolver', $classResolver);
                ObjectKVStore::put($mainContViewSubscriber, 'main_content_renderers', $mainContentRenderers);
            }
        );

        hook_method(
            'Drupal\Core\EventSubscriber\MainContentViewSubscriber',
            'onViewRenderArray',
            null,
            static function ($mainContViewSubscriber, $scope, $args) {
                // Called on the kernel.view event => active span should be symfony.kernel.view
                $span = active_span();

                if ($span->name === 'symfony.kernel.view') {
                    $classResolver = ObjectKVStore::get($mainContViewSubscriber, 'class_resolver');
                    $mainContentRenderers = ObjectKVStore::get($mainContViewSubscriber, 'main_content_renderers');

                    /** @var \Symfony\Component\HttpKernel\Event\ViewEvent $event */
                    $event = $args[0];
                    $request = $event->getRequest();
                    $result = $event->getControllerResult();
                    if ($classResolver
                        && $mainContentRenderers
                        && is_array($result)
                        && ($request->query->has(MainContentViewSubscriber::WRAPPER_FORMAT)
                            || $request->getRequestFormat() == 'html'
                        )
                    ) {
                        $wrapper = $request->query->get(MainContentViewSubscriber::WRAPPER_FORMAT, 'html');

                        // Fall back to HTML if the requested wrapper envelope is not available.
                        $wrapper = isset($mainContentRenderers[$wrapper]) ? $wrapper : 'html';

                        $renderer = $classResolver->getInstanceFromDefinition($mainContentRenderers[$wrapper]);
                        if ($renderer) {
                            $span->resource = get_class($renderer);
                        }
                    }
                }
            }
        );

        trace_method(
            'Symfony\Component\HttpFoundation\Response',
            'send',
            static function (SpanData $span) {
                $span->name = 'symfony.response.send';
                $span->service = \ddtrace_config_app_name('drupal');
                $span->type = Type::WEB_SERVLET;
                $span->meta[Tag::COMPONENT] = self::NAME;
            }
        );

        hook_method(
            'Drupal\Core\Session\AccountProxy',
            'setAccount',
            static function ($This, $scope, $args) {
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

                    $metadata = array_filter($metadata, static function ($value) {
                        return !empty($value);
                    });

                    set_user($account->id(), $metadata);
                }
            }
        );

        return Integration::LOADED;
    }
}
