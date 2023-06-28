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

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $file = isset($backtrace[3]['file']) ? $backtrace[3]['file'] : '';

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
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $file = isset($backtrace[3]['file']) ? $backtrace[3]['file'] : '';

        // Try to find the plugin associated to the hook
        $plugin = WordPressComponent::extractPluginNameFromFile($file);

        if ($plugin) { // Save the plugin name for future calls
            $actionHookToPlugin[$hookName] = $plugin;
        } else { // Try to save time on future calls
            $actionHookToPlugin[$hookName] = null;
        }

        return $actionHookToPlugin[$hookName];
    }

    public function load(WordPressIntegration $integration)
    {
        $rootSpan = \DDTrace\root_span();
        if (!$rootSpan) {
            return Integration::NOT_LOADED;
        }

        $actionHookToPlugin = [];
        $actionHookToTheme = [];

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
            \DDTrace\hook_function('wp_plugin_directory_constants', function () use ($rootSpan) {
                if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                    $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize(home_url(add_query_arg($_GET)));
                }
            });
        }

        // Core
        \DDTrace\trace_method('WP', 'main', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.main';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('WP', 'init', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.init';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;

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

        \DDTrace\trace_method('WP', 'parse_request', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.parse_request';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('WP', 'send_headers', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.send_headers';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('WP', 'query_posts', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.query_posts';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('WP', 'handle_404', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.handle_404';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('WP', 'register_globals', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.register_globals';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('create_initial_post_types', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'create_initial_post_types';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('create_initial_taxonomies', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'create_initial_taxonomies';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('wp_print_head_scripts', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_print_head_scripts';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        // These not called in PHP 5 due to call_user_func_array() bug
        \DDTrace\trace_function('wp_maybe_load_widgets', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_maybe_load_widgets';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('wp_maybe_load_embeds', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_maybe_load_embeds';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('_wp_customize_include', function (SpanData $span) use ($service) {
            $span->name = $span->resource = '_wp_customize_include';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        // Widgets
        \DDTrace\trace_method('WP_Widget', 'display_callback', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP_Widget.display_callback';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        // Views
        \DDTrace\trace_function('get_header', function (SpanData $span, array $args) use ($service) {
            $span->name = 'get_header';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('the_custom_header_markup', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'the_custom_header_markup';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('body_class', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'body_class';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('load_template', function (SpanData $span, array $args) use ($service) {
            $span->name = 'load_template';


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
                $span->resource = "(template: $template)";
            } else {
                $span->resource = !empty($template) ? $template : $span->name;
            }

            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('comments_template', function (SpanData $span, array $args) use ($service) {
            $span->name = 'comments_template';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('get_sidebar', function (SpanData $span, array $args) use ($service) {
            $span->name = 'get_sidebar';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('dynamic_sidebar', function (SpanData $span, array $args) use ($service) {
            $span->name = 'dynamic_sidebar';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('get_footer', function (SpanData $span, array $args) use ($service) {
            $span->name = 'get_footer';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        $action = function (SpanData $span, $args) use (&$actionHookToPlugin, &$actionHookToTheme) {
            $span->name = 'action';
            $hookName = isset($args[0]) ? $args[0] : '?';
            $span->resource = "(hook_name: $hookName)";

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

            // TODO: Search for more relevant tags
        };

        // Actions
        foreach (['do_action', 'do_action_ref_array'] as $function) {
            trace_function(
                $function,
                [
                    'recurse' => true,
                    'prehook' => function (SpanData $span, $args) use ($action, $service) {
                        $span->type = Type::WEB_SERVLET;

                        $action($span, $args);

                        $span->service = $service;
                        $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
                    },
                    'posthook' => function (SpanData $span, $args, $response) {
                        $duration = $span->getDuration(); // nanoseconds
                        // If the duration is less than 10ms, drop the span (return false)
                        return $duration > 10000000;
                    }
                ]
            );
        }

        // Filters - Too verbose

        // Blocks
        trace_function(
            'render_block',
            function (SpanData $span, $args, $retval) use ($service) {
                $span->name = 'block';
                // Some blocks are literally empty. We could even consider dropping the span in this case.
                $blockName = isset($args[0]['blockName']) ? $args[0]['blockName'] : '?';
                $span->resource = "(block_name: $blockName)";

                if (isset($args[0]['attrs'])) {
                    $attrs = $args[0]['attrs'];
                    foreach (['slug', 'theme', 'area', 'tagName'] as $attr) {
                        if (isset($attrs[$attr])) {
                            $span->meta["wp.template_part.$attr"] = $attrs[$attr];
                        }
                    }
                }

                $span->service = $service;
                $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
            }
        );

        trace_function('block_template_part', function (SpanData $span, $args) {
            $span->name = 'block_template_part';
            $part = isset($args[0]) && is_string($args[0]) ? $args[0] : '?';
            $span->resource = "(part: $part)";
        });

        trace_function('get_query_template', function (SpanData $span, $args) use ($service) {
            $span->type = Type::WEB_SERVLET;
            $span->name = 'template';

            $type = isset($args[0]) ? $args[0] : '?';
            $span->resource = "(type: $type)";

            $span->meta['wp.template.type'] = $type; // TODO: Redundant with resource? Search for more relevant tags
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        // Cookies
        trace_function('setcookie', function (SpanData $span, $args) use ($service) {
            $span->type = Type::WEB_SERVLET;
            $span->name = 'setcookie';

            $name = isset($args[0]) ? $args[0] : '?';
            $span->resource = "(name: $name)";

            $span->meta['wp.cookie.name'] = $name;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        hook_function('add_action', function ($args) use ($service, &$actionHookToPlugin) {
            $action = $args[0];
            $callback = $args[1];

            if (
                in_array(
                    $action,
                    [
                        'plugins_loaded',
                        'setup_theme',
                        'after_setup_theme',
                        'init',
                        'wp_loaded',
                        'template_redirect',
                        'wp', // part of wp->main();
                        'wp_head',
                        'rest_api_init',
                        'wp_footer',
                        'shutdown'
                    ]
                )
            ) {
                install_hook(
                    (
                    is_array($callback) && is_string($callback[0])
                        ? "{$callback[0]}::{$callback[1]}"
                        : $callback
                    ),
                    function (HookData $hook) use ($service, $callback, $action, &$actionHookToPlugin) {
                        $span = $hook->span();
                        $span->name = 'callback';
                        $span->type = Type::WEB_SERVLET;

                        if (is_array($callback)) {
                            list($class, $method) = $callback;
                            $class = explode('\\', is_object($class) ? get_class($class) : $class);
                            $class = end($class);
                            $span->resource = "(callback: $class::$method)";
                        } elseif (is_string($callback)) {
                            $function = explode('\\', $callback);
                            $function = end($function);
                            $span->resource = "(callback: $function)";
                        } else {
                            // TODO: Check if all types of callbacks are covered
                            $span->resource = '(callback: {closure})';
                        }

                        $span->service = $service;
                        $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;

                        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
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

        return Integration::LOADED;
    }
}
