<?php

namespace DDTrace\Integrations\WordPress\V6;

use DDTrace\HookData;
use DDTrace\Integrations\WordPress\WordPressIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;

use function DDTrace\active_span;
use function DDTrace\hook_function;
use function DDTrace\install_hook;
use function DDTrace\remove_hook;
use function DDTrace\set_user;
use function DDTrace\trace_function;
use function DDTrace\trace_method;

class WordPressComponent
{
    public static function extractPluginNameFromFile(string $file, bool $muPlugins = false): string
    {
        if ($muPlugins) {
            $pluginDir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/mu-plugins' : '');
        } else {
            $pluginDir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/plugins' : '');
        }

        if ($pluginDir && strpos($file, $pluginDir) === 0) {
            // The plugin name will be what follows the plugin dir
            // Format: <plugin_dir>/<plugin_name>/... or <plugin_dir>/<plugin_name>.php
            $plugin = substr($file, strlen($pluginDir) + 1);
            $plugin = explode('/', $plugin);
            return $plugin[0];
        } else {
            return '';
        }
    }

    public static function extractThemeNameFromFile(string $file): string
    {
        if (!function_exists('get_theme_root')) {
            return '';
        }

        $themeRoot = get_theme_root();
        $themePos = strpos($file, $themeRoot);
        if ($themePos === false) {
            return '';
        }

        $file = substr($file, $themePos + strlen($themeRoot)); // Remove everything before this position
        $themeName = explode('/', $file)[1]; // The theme name is the first directory
        $themeName = ucfirst($themeName); // Capitalize the first letter

        return $themeName ?: '';
    }

    public static function tryExtractThemeNameFromPath(string $hookName, array &$actionHookToTheme)
    {
        if (array_key_exists($hookName, $actionHookToTheme)) {
            return $actionHookToTheme[$hookName];
        }

        // Order: 0: this function, 1: do_action function definition, 2: do_action call
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $file = isset($backtrace[2]['file']) ? $backtrace[2]['file'] : '';

        $themeName = WordPressComponent::extractThemeNameFromFile($file);
        $actionHookToTheme[$hookName] = $themeName ?: null;

        return $actionHookToTheme[$hookName];
    }

    public static function extractAndSavePluginNameFromFile(string $hookName, array &$actionHookToPlugin)
    {
        if (array_key_exists($hookName, $actionHookToPlugin)) {
            return $actionHookToPlugin[$hookName];
        }

        // Get the path of the file that contains the hook
        // Order: 0: this function, 1: do_action function definition, 2: do_action call
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $file = isset($backtrace[2]['file']) ? $backtrace[2]['file'] : '';

        // Try to find the plugin associated to the hook
        $plugin = WordPressComponent::extractPluginNameFromFile($file);
        $actionHookToPlugin[$hookName] = $plugin ?: null;

        return $actionHookToPlugin[$hookName];
    }

    public static function setCommonTags(WordPressIntegration $integration, SpanData $span, string $name, string $resource = null)
    {
        $span->name = $name;
        $span->resource = $resource ?: $name;
        $span->type = Type::WEB_SERVLET;
        $span->service = $integration->getServiceName();
        $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
    }

    public static function getInterestingActions()
    {
        $interestingActions = [
            'plugins_loaded' => true,
            'setup_theme' => true,
            'after_setup_theme' => true,
            'init' => true,
            'wp_loaded' => true,
            'template_redirect' => true,
            'wp' => true, // part of wp->main();
            'wp_head' => true,
            'rest_api_init' => true,
            'wp_footer' => true,
            'shutdown' => true
        ];

        $additionalActionHookNames = dd_trace_env_config("DD_TRACE_WP_ADDITIONAL_ACTIONS");
        if (!empty($additionalActionHookNames)) {
            foreach ($additionalActionHookNames as $hookName) {
                $interestingActions[$hookName] = true;
            }
        }

        return $interestingActions;
    }

    public static function allowQueryParamsInResourceName()
    {
        // Check if the WordPress app is using plain permalinks
        $structure = get_option('permalink_structure');
        if ($structure !== '') {
            return;
        }

        $envVar = dd_trace_env_config("DD_TRACE_RESOURCE_URI_QUERY_PARAM_ALLOWED"); // <param> => null

        if (!empty($envVar)) {
            foreach (['p', 'page_id', 'post_type'] as $param) {
                if (!array_key_exists($param, $envVar)) {
                    $envVar[$param] = null;
                }
            }
        } else {
            $envVar = [
                'p' => null,
                'page_id' => null,
                'post_type' => null
            ];
        }

        $newEnvVar = implode(',', array_keys($envVar));
        ini_set('datadog.trace.resource_uri_query_param_allowed', $newEnvVar);
    }

    public function load(WordPressIntegration $integration)
    {
        if (!Integration::shouldLoad(WordPressIntegration::NAME)) {
            return Integration::NOT_LOADED;
        }

        // File loading
        hook_function('wp_plugin_directory_constants', null, function () use ($integration) {
            // wp_plugin_directory_constants is called before the plugins are loaded and defines the necessary constants
            // for wp_get_X_plugins functions to work.
            WordPressComponent::allowQueryParamsInResourceName();

            if (defined('ABSPATH') && defined('WPINC')) { // Just for a matter of safety :)
                $templateLoader = ABSPATH . WPINC . '/template-loader.php';
                install_hook(
                    $templateLoader,
                    function (HookData $hook) use ($integration) {
                        $span = $hook->span();
                        WordPressComponent::setCommonTags($integration, $span, 'load_file', 'file: template-loader.php');

                        remove_hook($hook->id);
                    }
                );
            }
        });


        hook_function('wp_templating_constants', null, function () use ($integration) {
            foreach (wp_get_active_and_valid_themes() as $theme) {
                if (file_exists($theme . '/functions.php')) {
                    install_hook(
                        $theme . '/functions.php',
                        function (HookData $hook) use ($integration, $theme) {
                            $span = $hook->span();
                            $themeName = explode('/', $theme);
                            $themeName = ucfirst(end($themeName));
                            WordPressComponent::setCommonTags($integration, $span, 'load_theme', "theme: $themeName");
                            $span->meta['wp.theme'] = $themeName;

                            remove_hook($hook->id);
                        }
                    );
                }
            }
        });

        hook_function('wp', function () use ($integration) {
            // Runs after wp-settings.php is loaded - i.e., after the entire core of WordPress functions is
            // loaded and the current user is populated
            $rootSpan = \DDTrace\root_span();
            if (!$rootSpan) {
                return;
            }

            // Overwrite the default web integration
            $integration->addTraceAnalyticsIfEnabled($rootSpan);
            $rootSpan->name = 'wordpress.request';
            $service = \ddtrace_config_app_name(WordPressIntegration::NAME);
            $rootSpan->service = $service;
            $rootSpan->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
            $rootSpan->meta[Tag::SPAN_KIND] = 'server';
            if ('cli' !== PHP_SAPI && !array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize(home_url(add_query_arg($_GET)));
            }

            $user = wp_get_current_user();
            if ($user) {
                $meta = [];
                if ($user->user_login) {
                    $meta['username'] = $user->user_login;
                }
                if ($user->user_email) {
                    $meta['email'] = $user->user_email;
                }
                if ($user->display_name) {
                    $meta['name'] = $user->display_name;
                }

                set_user($user->ID, $meta);
            }
        });

        $actionHookToPlugin = [];
        $actionHookToTheme = [];
        $interestingActions = WordPressComponent::getInterestingActions();

        // Core
        trace_method('WP', 'main', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'WP.main');
        });

        trace_method('WP', 'init', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'WP.init');
        });

        trace_method('WP', 'parse_request', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'WP.parse_request');
        });

        trace_method('WP', 'send_headers', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'WP.send_headers');
        });

        trace_method('WP', 'query_posts', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'WP.query_posts');
        });

        trace_method('WP', 'handle_404', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'WP.handle_404');
        });

        trace_method('WP', 'register_globals', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'WP.register_globals');
        });

        trace_function('create_initial_post_types', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'create_initial_post_types');
        });

        trace_function('create_initial_taxonomies', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'create_initial_taxonomies');
        });

        trace_function('wp_print_head_scripts', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'wp_print_head_scripts');
        });

        trace_function('wp_maybe_load_embeds', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'wp_maybe_load_embeds');
        });

        trace_function('_wp_customize_include', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, '_wp_customize_include');
        });

        // Widgets
        trace_function('wp_widgets_init', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'wp_widgets_init');
        });

        // These not called in PHP 5 due to call_user_func_array() bug
        trace_function('wp_maybe_load_widgets', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'wp_maybe_load_widgets');
        });

        /* When a widget is registered, trace its `widget` method. The base
         * method, WP_Widget::widget, is not called, so we cannot intercept it
         * generically.
         *
         * Widgets have largely been replaced by blocks in WordPress 6.
         */
        hook_function('register_widget', function ($args) use ($integration) {
            if (!isset($args[0])) {
                return;
            }

            // register_widget( string|WP_Widget $widget ): void
            $widget = $args[0];
            if (is_string($widget)) {
                $className = $widget;
            } elseif (is_object($widget)) {
                $className = get_class($widget);
            } else {
                return;
            }

            trace_method($className, 'widget', function (SpanData $span) use ($integration) {
                WordPressComponent::setCommonTags(
                    $integration,
                    $span,
                    'widget',
                    isset($this->name) ? "name: {$this->name}" : 'name: ?'
                );
            });
        });

        // Views
        trace_function('get_header', function (SpanData $span, array $args) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'get_header', !empty($args[0]) ? $args[0] : 'get_header');
        });

        trace_function('get_footer', function (SpanData $span, array $args) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'get_footer', !empty($args[0]) ? $args[0] : 'get_footer');
        });

        trace_function('the_custom_header_markup', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'the_custom_header_markup');
        });

        trace_function('body_class', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'body_class');
        });

        trace_function('load_template', function (SpanData $span, array $args) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'load_template');

            $template = isset($args[0]) ? wp_basename($args[0]) : '';
            $plugin = WordPressComponent::extractPluginNameFromFile($template);
            if ($plugin) {
                $span->meta['wp.plugin'] = $plugin;
            } else {
                // Order: this function, load_template
                $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
                $file = isset($backtrace[1]['file']) ? $backtrace[1]['file'] : '';
                $plugin = WordPressComponent::extractPluginNameFromFile($file);
                if ($plugin) {
                    $span->meta['wp.plugin'] = $plugin;
                } elseif (($theme = wp_get_theme()->get('Name'))) {
                    $span->meta['wp.theme'] = $theme;
                }
            }

            // Remove the trailing .php extension, if any
            if (substr($template, -4) === '.php') {
                $template = substr($template, 0, -4);
                $span->meta['wp.template'] = $template;
                $span->resource = "template: $template";
            } else {
                $span->resource = !empty($template) ? $template : $span->name;
            }
        });

        trace_function('the_content', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'the_content');

            $postID = get_the_ID();
            if ($postID) {
                $span->meta['wp.post.id'] = $postID;
            }
        });

        trace_function('the_post', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'the_post');
        });

        trace_function('get_avatar', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'get_avatar');
        });

        trace_function('the_post_thumbnail', function (SpanData $span, array $args) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'the_post_thumbnail');

            if (isset($args[0]) && is_string($args[0])) {
                $span->meta['wp.post.thumbnail_size'] = $args[0];
            }
        });

        trace_function('comments_template', function (SpanData $span, array $args) use ($integration) {
            WordPressComponent::setCommonTags(
                $integration,
                $span,
                'comments_template',
                !empty($args[0]) ? $args[0] : 'comments_template'
            );
        });

        // Blocks
        trace_function(
            'render_block',
            function (SpanData $span, $args, $retval) use ($integration) {
                // Some blocks are literally empty. We could even consider dropping the span in this case.
                WordPressComponent::setCommonTags(
                    $integration,
                    $span,
                    'block',
                    isset($args[0]['blockName']) ? "block_name: {$args[0]['blockName']}" : 'block_name: ?'
                );

                if (isset($args[0]['attrs'])) {
                    $attrs = $args[0]['attrs'];
                    // See https://developer.wordpress.org/themes/block-themes/templates-and-template-parts/#block-c5fa39a2-a27d-4bd2-98d0-dc6249a0801a
                    foreach (['slug', 'theme', 'area', 'tagName'] as $attr) {
                        if (isset($attrs[$attr])) {
                            $span->meta["wp.template_part.$attr"] = $attrs[$attr];
                        }
                    }
                }
            }
        );

        trace_function('block_template_part', function (SpanData $span, $args) use ($integration) {
            WordPressComponent::setCommonTags(
                $integration,
                $span,
                'block_template_part',
                isset($args[0]) && is_string($args[0]) ? "part: {$args[0]}" : 'part: ?'
            );
        });

        trace_function('get_query_template', function (SpanData $span, $args, $path) use ($integration) {
            WordPressComponent::setCommonTags(
                $integration,
                $span,
                'template',
                isset($args[0]) ? "type: {$args[0]}" : 'type: ?'
            );

            $themeName = WordPressComponent::extractThemeNameFromFile($path);
            if ($themeName) {
                $span->meta['wp.theme'] = $themeName;
            }
        });

        // Sidebar
        trace_function('get_sidebar', function (SpanData $span, array $args) use ($integration) {
            WordPressComponent::setCommonTags(
                $integration,
                $span,
                'get_sidebar',
                !empty($args[0]) ? $args[0] : 'get_sidebar'
            );
        });

        trace_function('dynamic_sidebar', function (SpanData $span, array $args) use ($integration) {
            WordPressComponent::setCommonTags(
                $integration,
                $span,
                'dynamic_sidebar',
                isset($args[0]) ? $args[0] : 'dynamic_sidebar'
            );
        });

        // Actions
        foreach (['do_action', 'do_action_ref_array'] as $function) {
            trace_function(
                $function,
                [
                    'recurse' => true,
                    'prehook' => function (SpanData $span, $args) use ($integration, &$actionHookToPlugin, &$actionHookToTheme, $interestingActions) {
                        if (isset($args[0]) && isset($interestingActions[$args[0]])) {
                            WordPressComponent::setCommonTags($integration, $span, 'action');

                            $hookName = isset($args[0]) ? $args[0] : '?';
                            $span->resource = "hook_name: $hookName";

                            if ($hookName === '?') {
                                return;
                            }

                            // If we have a plugin name, add it to the meta
                            // TODO: Replace backtrace usage either with &$plugins, or $span->sourceFile
                            if (isset($actionHookToPlugin[$hookName])) { // Don't waste time if it gave null before
                                if ($actionHookToPlugin[$hookName]) {
                                    $span->meta['wp.plugin'] = $actionHookToPlugin[$hookName];
                                }
                            } elseif ($plugin = WordPressComponent::extractAndSavePluginNameFromFile($hookName, $actionHookToPlugin)) {
                                $span->meta['wp.plugin'] = $plugin;
                            } elseif ($theme = WordPressComponent::tryExtractThemeNameFromPath($hookName, $actionHookToTheme)) {
                                $span->meta['wp.theme'] = $theme;
                            }

                            return true;
                        } else {
                            return false;
                        }
                    }
                ]
            );
        }

        $service = $integration->getServiceName();
        static $plugin_loading_funcs = [
            'wp_get_active_and_valid_plugins',
            'wp_get_active_network_plugins',
            'wp_get_mu_plugins',
        ];
        $plugins = [];
        foreach ($plugin_loading_funcs as $plugin_loading_func) {
            \DDTrace\install_hook(
                $plugin_loading_func,
                null,
                function (HookData $hook) use (&$plugins, $plugin_loading_func, $integration) {
                    foreach ($hook->returned as $plugin) {
                        // Use the plugin's basename instead of absolute path. For example,
                        // instead of:
                        //   /var/www/html/wp-content/plugins/hello.php
                        // Just use `hello.php`.
                        $basename = \plugin_basename($plugin);

                        // 2. Track when each plugin is loaded, so in the add_filter callbacks we
                        // know which plugin installs the hook.
                        if (is_link($plugin)) {
                            $plugin = \readlink($plugin);
                        }
                        \DDTrace\install_hook(
                            $plugin,
                            function (HookData $hook) use (&$plugins, $basename, $plugin, $integration) {
                                $plugins[] = $hook->data = $basename;

                                $span = $hook->span();
                                WordPressComponent::setCommonTags($integration, $span, 'load_plugin', "plugin: $basename");
                                $span->meta['wp.plugin'] = $basename; // TODO: See whether it is better to use the basename, or the first part (before the first /, if any)
                                $span->meta['wp.plugin_file'] = $plugin;
                            },
                            function ($hook) use (&$plugins) {
                                $top = \array_pop($plugins);
                                // Integrity check; should be stackful.
                                assert($top === $hook->data);
                            }
                        );

                        // todo: emit instrumentation telemetry?
                    }
                });
        }



        // 3. Hook actions and filters loaded by each plugin.
        $add_hook_begin = function (HookData $hook) use (&$plugins, $service, $interestingActions) {
            // The action/filter is only interesting if a plugin installed it.
            if (!empty($plugins)) {
                // Assign the hook to the plugin at the top of the stack.
                $plugin = \end($plugins);

                // Signature: add_filter(string $hook_name, callable $callback, ...)
                if (isset($hook->args[1])) {
                    $callback = $hook->args[1];

                    // 4. Measure the execution time of $callback.

                    \DDTrace\install_hook(
                        is_array($callback) && is_string($callback[0])
                            ? "{$callback[0]}::{$callback[1]}"
                            : $callback,
                        function (HookData $hook) use ($plugin, $service, $callback) {
                            $hook->data = \hrtime(true);
                            $span = $hook->span();
                            $span->name = 'wordpress.plugin.hook';
                            //$span->resource = "(plugin:$plugin)";
                            $span->service = $service;

                            if (is_array($callback)) {
                                if (is_object($callback[0])) {
                                    $class = get_class($callback[0]);
                                    $class = explode('\\', $class);
                                    $class = end($class);
                                    $resource = "callback: {$class}::{$callback[1]}";
                                } else {
                                    $class = explode('\\', $callback[0]);
                                    $class = end($class);
                                    $resource = "callback: {$callback[0]}::{$callback[1]}";
                                }
                            } elseif (is_string($callback)) {
                                $function = explode('\\', $callback);
                                $function = end($function);
                                $resource = "callback: {$function}";
                            } elseif (is_object($callback)) {
                                // A Closure will end up here
                                // The Closure will also have the closure.declaration tag, set by the extension
                                $resource = "callback: " . get_class($callback);
                            } else {
                                // Shouldn't happen :)
                                $resource = 'callback: unknown';
                            }

                            $span->resource = $resource;
                            $span->meta['wp.plugin'] = $plugin;
                        },
                        function (HookData $hook) use ($plugin) {
                            $elapsed = \hrtime(true) - $hook->data;
                            if (($span = $hook->span())) {
                                $span->metrics['elapsed_ns'] = $elapsed;
                            }
                        }
                    );

                }
            }
        };

        hook_function('add_action', function ($args) use ($integration, &$actionHookToPlugin, $interestingActions, &$plugins) {
            $action = $args[0];
            $callback = $args[1];
            $pluginName = end($plugins);

            if (isset($interestingActions[$action])) {
                install_hook(
                    (
                    is_array($callback) && is_string($callback[0])
                        ? "{$callback[0]}::{$callback[1]}"
                        : $callback
                    ),
                    function (HookData $hook) use ($integration, $callback, $action, &$actionHookToPlugin, $pluginName) {
                        $span = $hook->span();

                        if (is_array($callback)) {
                            if (is_object($callback[0])) {
                                $class = get_class($callback[0]);
                                $class = explode('\\', $class);
                                $class = end($class);
                                $resource = "callback: {$class}::{$callback[1]}";
                            } else {
                                $class = explode('\\', $callback[0]);
                                $class = end($class);
                                $resource = "callback: {$callback[0]}::{$callback[1]}";
                            }
                        } elseif (is_string($callback)) {
                            $function = explode('\\', $callback);
                            $function = end($function);
                            $resource = "callback: {$function}";
                        } elseif (is_object($callback)) {
                            // A Closure will end up here
                            // The Closure will also have the closure.declaration tag, set by the extension
                            $class = get_class($callback);
                            $class = explode('\\', $class);
                            $class = end($class);
                            $resource = "callback: $class";
                        } else {
                            // Shouldn't happen :)
                            $resource = 'callback: unknown';
                        }

                        WordPressComponent::setCommonTags($integration, $span, 'callback', $resource);

                        $pluginRoot = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/plugins' : '');
                        $themeRoot = get_theme_root();
                        if (strpos($span->sourceFile, $pluginRoot) !== false) {
                            $plugin = substr($span->sourceFile, strlen($pluginRoot) + 1);
                            $plugin = explode('/', $plugin);
                            $span->meta['wp.plugin'] = $plugin[0];
                        } elseif (strpos($span->sourceFile, $themeRoot) !== false) {
                            $span->meta['wp.theme'] = wp_get_theme()->get('Name');
                        } elseif ($pluginName) {
                            $span->meta['wp.plugin'] = $pluginName;
                        }

                        remove_hook($hook->id);
                    }
                );
            }
        });

        // Note: add_action delegates to add_filter.
        //\DDTrace\install_hook('add_action', $add_hook_begin, null);

        return Integration::LOADED;
    }
}
