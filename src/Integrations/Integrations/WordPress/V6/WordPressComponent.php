<?php

namespace DDTrace\Integrations\WordPress\V6;

use DDTrace\HookData;
use DDTrace\Integrations\WordPress\WordPressIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;

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
            // Format: <plugin_dir>/<plugin_name>/... or <plugin_dir>/<plugin_name.php>
            $plugin = substr($file, strlen($pluginDir) + 1);
            $plugin = explode('/', $plugin);
            return $plugin[0]; // Keeps the .php extension if it's a single file plugin (e.g., hello.php for Hello Dolly)
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

        $themeName = wp_get_theme()->get('Name');
        if (!empty($themeName)) {
            return $themeName;
        }

        $file = substr($file, $themePos + strlen($themeRoot)); // Remove everything before this position
        $themeName = explode('/', $file)[1]; // The theme name is the first directory
        $themeName = ucfirst($themeName); // Capitalize the first letter (so it matches WordPress's formatting)

        return $themeName ?: '';
    }

    public static function extractAndSaveThemeNameFromSpan(string $file, string $hookName, array &$actionHookToTheme)
    {
        if (array_key_exists($hookName, $actionHookToTheme)) {
            return $actionHookToTheme[$hookName];
        }

        $themeName = WordPressComponent::extractThemeNameFromFile($file);
        $actionHookToTheme[$hookName] = $themeName ?: null;

        return $actionHookToTheme[$hookName];
    }

    public static function extractAndSavePluginNameFromSpan(string $file, string $hookName, array &$actionHookToPlugin)
    {
        if (array_key_exists($hookName, $actionHookToPlugin)) {
            return $actionHookToPlugin[$hookName];
        }

        // Try to find the plugin associated to the hook
        $plugin = WordPressComponent::extractPluginNameFromFile($file);
        $actionHookToPlugin[$hookName] = $plugin ?: null;

        return $actionHookToPlugin[$hookName];
    }

    public static function getPrettyCallbackName($callback): string
    {
        if (is_array($callback)) {
            $class = is_object($callback[0])
                ? get_class($callback[0])
                : (is_string($callback[0]) ? $callback[0] : null);
            if ($class) {
                $class = explode('\\', $class);
                $class = end($class);
                return "$class::{$callback[1]}";
            }
        } elseif (is_string($callback)) {
            $function = explode('\\', $callback);
            return end($function);
        } elseif (is_object($callback)) {
            // An instance of Closure will end up here
            // The Closure will also have the closure.declaration tag, set by the extension
            $class = get_class($callback);
            $class = explode('\\', $class);
            return end($class);
        }

        return '?';
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
        static $interestingActions = [
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

    public static function setSpansLimit()
    {
        // Safety measure - Adds 10 spans / plugin (arbitrary)
        $pluginCount = count(wp_get_active_and_valid_plugins());
        $spansLimit = 1000 + ($pluginCount * 10);

        $currentLimit = ini_get('datadog.trace.spans_limit');
        $spansLimit = max($spansLimit, $currentLimit);
        ini_set('datadog.trace.spans_limit', $spansLimit);
    }

    public function load(WordPressIntegration $integration)
    {
        $rootSpan = \DDTrace\root_span();
        if (!$rootSpan) {
            return Integration::NOT_LOADED;
        }

        // Overwrite the default web integration
        $integration->addTraceAnalyticsIfEnabled($rootSpan);
        $rootSpan->name = 'wordpress.request';
        $rootSpan->service = $integration->getServiceName();
        $rootSpan->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        $rootSpan->meta[Tag::SPAN_KIND] = 'server';
        if ('cli' !== PHP_SAPI) {
            $normalizedPath = Normalizer::uriNormalizeincomingPath($_SERVER['REQUEST_URI']);
            $rootSpan->resource = $_SERVER['REQUEST_METHOD'] . ' ' . $normalizedPath;
            if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize(home_url(add_query_arg($_GET)));
            }
        }

        // File loading
        hook_function('wp_plugin_directory_constants', null, function () use ($integration) {
            WordPressComponent::allowQueryParamsInResourceName();

            if (defined('ABSPATH') && defined('WPINC')) { // Just for a matter of safety :)
                $templateLoader = ABSPATH . WPINC . '/template-loader.php';
                install_hook(
                    $templateLoader,
                    function (HookData $hook) use ($integration) {
                        $span = $hook->span();
                        WordPressComponent::setCommonTags($integration, $span, 'load_template_loader');

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
                            WordPressComponent::setCommonTags($integration, $span, 'load_theme', "$themeName (theme)");
                            $span->meta['wp.theme'] = $themeName;

                            remove_hook($hook->id);
                        }
                    );
                }
            }
        });

        hook_function('wp', function () use ($integration) {
            if (dd_trace_env_config('DD_TRACE_WP_CALLBACKS')) {
                WordPressComponent::setSpansLimit();
            }

            // Runs after wp-settings.php is loaded - i.e., after the entire core of WordPress functions is
            // loaded and the current user is populated
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
                    isset($this->name) ? "{$this->name} (widget)" : '? (widget)'
                );

                if (isset($this->name)) {
                    $span->meta['wp.widget'] = $this->name;
                }
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

            $templateFile = $args[0];
            if ($plugin = WordPressComponent::extractPluginNameFromFile($templateFile)) {
                $span->meta['wp.plugin'] = $plugin;
            } elseif ($theme = WordPressComponent::extractThemeNameFromFile($templateFile)) {
                $span->meta['wp.theme'] = $theme;
            }

            $span->meta['wp.template_file'] = $templateFile;
            if (substr($templateFile, -4) === '.php') {
                $templatePart = explode('/', $templateFile);
                $templatePart = end($templatePart);
                $templatePart = substr($templatePart, 0, -4);
                $span->resource = "$templatePart (template)";
                $span->meta['wp.template_part'] = $templatePart;
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
                    isset($args[0]['blockName']) ? "{$args[0]['blockName']} (block)" : null
                );

                if (isset($args[0]['blockName'])) {
                    $span->meta['wp.block_name'] = $args[0]['blockName'];
                }

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
                isset($args[0]) && is_string($args[0]) ? "{$args[0]} (part)" : null
            );

            if (isset($args[0]) && is_string($args[0])) {
                $span->meta['wp.template_part'] = $args[0];
            }

        });

        trace_function('get_query_template', function (SpanData $span, $args, $path) use ($integration) {
            WordPressComponent::setCommonTags(
                $integration,
                $span,
                'template',
                isset($args[0]) ? "{$args[0]} (type)" : null
            );

            $themeName = WordPressComponent::extractThemeNameFromFile($path);
            if ($themeName) {
                $span->meta['wp.theme'] = $themeName;
            }

            if (isset($args[0]) && is_string($args[0])) {
                $span->meta['wp.template_type'] = $args[0];
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
            install_hook(
                $function,
                function (HookData $hook) use ($integration, &$actionHookToPlugin, &$actionHookToTheme, $interestingActions) {
                    $args = $hook->args;

                    if (isset($args[0]) && isset($interestingActions[$args[0]])) {
                        $span = $hook->span();
                        WordPressComponent::setCommonTags($integration, $span, 'action');

                        $hookName = isset($args[0]) ? $args[0] : '?';

                        if ($hookName === '?') {
                            return;
                        }

                        $span->resource = "$hookName (hook)";
                        $span->meta['wp.hook'] = $hookName;

                        if (isset($actionHookToPlugin[$hookName])) { // Don't waste time if it gave null before
                            if ($actionHookToPlugin[$hookName]) {
                                $span->meta['wp.plugin'] = $actionHookToPlugin[$hookName];
                            }
                        } else {
                            $file = $hook->getSourceFile();
                            if ($plugin = WordPressComponent::extractAndSavePluginNameFromSpan($file, $hookName, $actionHookToPlugin)) {
                                $span->meta['wp.plugin'] = $plugin;
                            } elseif ($theme = WordPressComponent::extractAndSaveThemeNameFromSpan($file, $hookName, $actionHookToTheme)) {
                                $span->meta['wp.theme'] = $theme;
                            }
                        }
                    }
                }
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
                        if (is_link($plugin)) {
                            $plugin = \readlink($plugin);
                        }

                        $getPrettyPluginNameFn = function ($file) use ($plugin_loading_func) {
                            $pluginName = WordPressComponent::extractPluginNameFromFile($file, strpos($plugin_loading_func, 'mu') !== false);
                            return $pluginName ?: basename($file);
                        };

                        \DDTrace\install_hook(
                            $plugin,
                            function (HookData $hook) use (&$plugins, $plugin, $integration, $getPrettyPluginNameFn) {
                                $pluginName = $getPrettyPluginNameFn($plugin);
                                $plugins[] = $hook->data = $pluginName;

                                $span = $hook->span();
                                WordPressComponent::setCommonTags($integration, $span, 'load_plugin', "$pluginName (plugin)");
                                $span->meta['wp.plugin'] = $pluginName;
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

        hook_function('add_action', function ($args) use ($integration, &$actionHookToPlugin, $interestingActions, &$plugins) {
            $action = $args[0];
            $callback = $args[1];
            $pluginName = end($plugins);
            if (isset($interestingActions[$action]) && dd_trace_env_config('DD_TRACE_WP_CALLBACKS')) {
                install_hook(
                    (
                    is_array($callback) && is_string($callback[0])
                        ? "{$callback[0]}::{$callback[1]}"
                        : $callback
                    ),
                    function (HookData $hook) use ($integration, $callback, $action, &$actionHookToPlugin, $pluginName) {
                        $span = $hook->span();

                        $callbackName = WordPressComponent::getPrettyCallbackName($callback);
                        WordPressComponent::setCommonTags($integration, $span, 'callback', $callbackName . ' (callback)');
                        $span->meta['wp.callback'] = $callbackName;

                        $file = $hook->getSourceFile();
                        if ($plugin = WordPressComponent::extractPluginNameFromFile($file)) {
                            $span->meta['wp.plugin'] = $plugin;
                        } elseif ($themeName = WordPressComponent::extractThemeNameFromFile($file)) {
                            $span->meta['wp.theme'] = $themeName;
                        } elseif ($pluginName) {
                            $span->meta['wp.plugin'] = $pluginName;
                        }

                        remove_hook($hook->id);
                    }
                );
            }

            return false; // Don't trace 'add_action'; we're only interested in the origin
        });

        return Integration::LOADED;
    }
}
