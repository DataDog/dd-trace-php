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

use function DDTrace\hook_function;
use function DDTrace\install_hook;
use function DDTrace\remove_hook;
use function DDTrace\set_user;
use function DDTrace\trace_function;
use function DDTrace\trace_method;

class WordPressComponent
{
    public static function extractPluginNameFromFile(string $file): string {
        $pluginDir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/plugins' : '');
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

    public static function tryExtractThemeNameFromPath(string $hookName, array &$actionHookToTheme)
    {
        if (array_key_exists($hookName, $actionHookToTheme)) {
            return $actionHookToTheme[$hookName];
        }

        // Order: 0: this function, 1: do_action function definition, 2: do_action call
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $file = isset($backtrace[2]['file']) ? $backtrace[2]['file'] : '';

        if (!function_exists('get_theme_root')) {
            return null;
        }

        $themeRoot = get_theme_root();
        $themePos = strpos($file, $themeRoot);
        if ($themePos === false) {
            return null;
        }

        $file = substr($file, $themePos + strlen($themeRoot)); // Remove everything before this position
        $themeName = explode('/', $file)[1]; // The theme name is the first directory
        $themeName = ucfirst($themeName); // Capitalize the first letter

        if ($themeName) {
            $actionHookToTheme[$hookName] = $themeName;
        } else {
            $actionHookToTheme[$hookName] = null;
        }

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

        if ($plugin) { // Save the plugin name for future calls
            $actionHookToPlugin[$hookName] = $plugin;
        } else { // Try to save time on future calls
            $actionHookToPlugin[$hookName] = null;
        }

        return $actionHookToPlugin[$hookName];
    }

    public static function setCommonTags(WordPressIntegration $integration, SpanData $span, string $name, string $resource = null) {
        $span->name = $name;
        $span->resource = $resource ?: $name;
        $span->type = Type::WEB_SERVLET;
        $span->service = $integration->getServiceName();
        $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
    }

    public static function allowQueryParamsInResourceName() {
        $envVar = array_keys(dd_trace_env_config("DD_TRACE_RESOURCE_URI_QUERY_PARAM_ALLOWED"));

        if (!in_array('*', $envVar)) {
            ini_set('datadog.trace.resource_uri_query_param_allowed', "*");
        }
    }

    public function load(WordPressIntegration $integration)
    {
        WordPressComponent::allowQueryParamsInResourceName();

        $rootSpan = \DDTrace\root_span();
        if (!$rootSpan) {
            return Integration::NOT_LOADED;
        }

        $actionHookToPlugin = [];
        $actionHookToTheme = [];
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

        // Overwrite the default web integration
        $integration->addTraceAnalyticsIfEnabled($rootSpan);
        $rootSpan->name = 'wordpress.request';
        $service = \ddtrace_config_app_name(WordPressIntegration::NAME);
        $rootSpan->service = $service;
        $rootSpan->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        $rootSpan->meta[Tag::SPAN_KIND] = 'server';
        if ('cli' !== PHP_SAPI) {
            $normalizedPath = Normalizer::uriNormalizeincomingPath($_SERVER['REQUEST_URI']);
            $rootSpan->resource = $_SERVER['REQUEST_METHOD'] . ' ' . $normalizedPath; // TODO: Change resource name
            hook_function('wp_plugin_directory_constants', function () use ($rootSpan) {
                if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                    $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize(home_url(add_query_arg($_GET)));
                }
            });
        }

        // Core
        trace_method('WP', 'main', function (SpanData $span) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'WP.main');
        });

        trace_method('WP', 'init', function (SpanData $span) use ($integration, $rootSpan) {
            WordPressComponent::setCommonTags($integration, $span, 'WP.init');

            $canonicalURL = wp_get_canonical_url();
            if ($canonicalURL) {
                $rootSpan->resource = $canonicalURL;
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
        // May be removed - covered by load_template
        trace_function('get_header', function (SpanData $span, array $args) use ($integration) {
            WordPressComponent::setCommonTags($integration, $span, 'get_header', !empty($args[0]) ? $args[0] : 'get_header');
        });

        // May be removed - covered by load_template
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
            } elseif (($theme = wp_get_theme()->get('Name'))) {
                $span->meta['wp.theme'] = $theme;
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

        // May be removed - covered by load_template
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

        foreach (['do_action', 'do_action_ref_array'] as $function) {
            trace_function(
                $function,
                [
                    'recurse' => true,
                    'prehook' => function (SpanData $span, $args) use ($integration, &$actionHookToPlugin, &$actionHookToTheme) {
                        WordPressComponent::setCommonTags($integration, $span, 'action');

                        $hookName = isset($args[0]) ? $args[0] : '?';
                        $span->resource = "hook_name: $hookName";

                        if ($hookName === '?') {
                            return;
                        }

                        // If we have a plugin name, add it to the meta
                        if (isset($actionHookToPlugin[$hookName])) {
                            if ($actionHookToPlugin[$hookName]) {
                                $span->meta['wp.plugin'] = $actionHookToPlugin[$hookName];
                            }
                        } elseif ($plugin = WordPressComponent::extractAndSavePluginNameFromFile($hookName, $actionHookToPlugin)) {
                            $span->meta['wp.plugin'] = $plugin;
                        } elseif ($theme = WordPressComponent::tryExtractThemeNameFromPath($hookName, $actionHookToTheme)) {
                            $span->meta['wp.theme'] = $theme;
                        }

                        // TODO: Search for even more relevant tags
                    },
                    'posthook' => function (SpanData $span, $args, $response) {
                        $duration = $span->getDuration(); // nanoseconds
                        // If the duration is less than 10ms, drop the span (return false)
                        // On an app with approx. 15 plugins, reduced the number of spans by 50% (1500 -> 750)
                        return $duration > 10000000;
                    }
                ]
            );
        }

        hook_function('add_action', function ($args) use ($integration, &$actionHookToPlugin, $interestingActions) {
            $action = $args[0];
            $callback = $args[1];

            if (isset($interestingActions[$action])) {
                install_hook(
                    (
                    is_array($callback) && is_string($callback[0])
                        ? "{$callback[0]}::{$callback[1]}"
                        : $callback
                    ),
                    function (HookData $hook) use ($integration, $callback, $action, &$actionHookToPlugin) {
                        $span = $hook->span();

                        // Order: 1. function definition, 2. call_user_func/call_user_func_array call (class-wp-hook.php)
                        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
                        $class = isset($backtrace[1]['class']) ? $backtrace[1]['class'] : null;
                        $function = isset($backtrace[1]['function']) ? $backtrace[1]['function'] : null;
                        // Remove the namespace from the class name
                        if ($class) {
                            $class = explode('\\', $class);
                            $class = end($class);
                        }
                        // Remove the namespace from the function name
                        if ($function) {
                            $function = explode('\\', $function);
                            $function = end($function);
                        }

                        $resource = $class ? "callback: $class::$function" : "callback: $function";

                        WordPressComponent::setCommonTags($integration, $span, 'callback', $resource);

                        $file = isset($backtrace[0]['file']) ? $backtrace[0]['file'] : '';
                        $plugin = WordPressComponent::extractPluginNameFromFile($file);
                        if ($plugin) {
                            $span->meta['wp.plugin'] = $plugin;
                        }

                        remove_hook($hook->id);
                    }
                );
            }
        });

        // Filters - Too verbose

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

        trace_function('get_query_template', function (SpanData $span, $args) use ($integration) {
            WordPressComponent::setCommonTags(
                $integration,
                $span,
                'template',
                isset($args[0]) ? "type: {$args[0]}" : 'type: ?'
            );
        });

        return Integration::LOADED;
    }
}
