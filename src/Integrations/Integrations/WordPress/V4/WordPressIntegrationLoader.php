<?php

namespace DDTrace\Integrations\WordPress\V4;

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
use function DDTrace\trace_function;
use function DDTrace\trace_method;

class WordPressIntegrationLoader
{
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

        // Remove everything before this position
        $file = substr($file, $themePos + strlen($themeRoot));

        // The theme name is the first directory
        $themeName = explode('/', $file)[1];

        // Capitalize the first letter
        $themeName = ucfirst($themeName);

        $actionHookToTheme[$hookName] = $themeName;

        return $themeName;
    }

    public static function tryExtractPluginNameFromPath(string $hookName, array &$actionHookToPlugin)
    {
        if (array_key_exists($hookName, $actionHookToPlugin)) {
            return $actionHookToPlugin[$hookName];
        }

        // Try to find the plugin associated to the hook
        // 1. Retrieve the plugin dir (WP_PLUGIN_DIR, else WP_CONTENT_DIR . '/' plugins, else nothing)
        $pluginDir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/plugins' : '');
        if (empty($pluginDir)) {
            return null;
        }

        // 2. Get the path of the file that contains the hook
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $file = isset($backtrace[3]['file']) ? $backtrace[3]['file'] : '';
        // 3. If the file can be prefixed by the plugin dir, we have a winner
        if (strpos($file, $pluginDir) === 0) {
            // The plugin name will be what follows the plugin dir
            // Format: <plugin_dir>/<plugin_name>/... or <plugin_dir>/<plugin_name>.php
            $pluginName = substr($file, strlen($pluginDir) + 1);
            $pluginName = explode('/', $pluginName)[0];
            $pluginName = explode('.', $pluginName)[0];
            $actionHookToPlugin[$hookName] = $pluginName;
        } else {
            $actionHookToPlugin[$hookName] = null; // We set it to null to avoid doing the same thing again
        }

        return $actionHookToPlugin[$hookName];
    }

    public function load(WordPressIntegration $integration)
    {
        Logger::get()->debug('Loading WordPress integration');
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
            $rootSpan->resource = $_SERVER['REQUEST_METHOD'] . ' ' . $normalizedPath;
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

            $span->meta['wp.theme.name'] = wp_get_theme()->get('Name');

            $template = wp_basename($args[0]);
            // Remove the trailing .php extension, if any
            if (substr($template, -4) === '.php') {
                $template = substr($template, 0, -4);
                $span->meta['wp.theme.template'] = $template;
                $span->resource = "(template: $template)";
            } else {
                $span->resource = !empty($args[0]) ? $args[0] : $span->name;
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
            } elseif ($pluginName = WordPressIntegrationLoader::tryExtractPluginNameFromPath($hookName, $actionHookToPlugin)) {
                $span->meta['wp.plugin'] = $pluginName;
            } elseif ($themeName = WordPressIntegrationLoader::tryExtractThemeNameFromPath($hookName, $actionHookToTheme)) {
                $span->meta['wp.theme.name'] = $themeName;
            }
        };

        // TODO: REFACTOR THIS O M G
        $filter = function (SpanData $span, $args) use (&$actionHookToPlugin, &$actionHookToTheme) {
            $span->name = 'filter';
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
            } elseif ($pluginName = WordPressIntegrationLoader::tryExtractPluginNameFromPath($hookName, $actionHookToPlugin)) {
                $span->meta['wp.plugin'] = $pluginName;
            }
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

        // Filters
        /*
        foreach (['apply_filters', 'apply_filters_ref_array'] as $function) {
            trace_function(
                $function,
                [
                    'recurse' => false,
                    'prehook' => function (SpanData $span, $args) use ($filter, $service) {
                        $span->type = Type::WEB_SERVLET;

                        $filter($span, $args);

                        $span->service = $service;
                        $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
                    },
                    'posthook' => function (SpanData $span, $args, $response) {
                        $duration = $span->getDuration(); // nanoseconds
                        // If the duration is less than 1ms, drop the span (return false)
                        //return $duration > 10000;
                    }
                ]
            );
        }
        */


        // Blocks
        trace_function(
            'render_block',
            function (SpanData $span, $args) use ($service) {
                $span->name = 'block';
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

        trace_function('get_query_template', function (SpanData $span, $args) use ($service) {
            $span->type = Type::WEB_SERVLET;
            $span->name = 'template';

            $type = isset($args[0]) ? $args[0] : '?';
            $span->resource = "(type: $type)";

            $span->meta['wp.template.type'] = $type;
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

        \DDTrace\trace_method('WP_Styles', 'do_footer_items', function (SpanData $span) use ($service) {
            $span->type = Type::WEB_SERVLET;
            $span->name = 'do_footer_items';

            $span->resource = '(do_footer_items)';

            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        hook_function('add_action', function ($args) use ($service, &$actionHookToPlugin) {
            $action = $args[0];
            $callback = $args[1];

            if (in_array($action, ['plugins_loaded', 'init', 'wp_loaded', 'setup_theme', 'after_setup_theme', 'shutdown', 'wp', 'wp_head', 'wp_footer'])) {
                install_hook(
                    (is_array($callback) && $callback[0] === 'WP_Block_Supports' ? "{$callback[0]}::{$callback[1]}" : $callback),
                    function (HookData $hook) use ($service, $callback, $action, &$actionHookToPlugin) {
                        $span = $hook->span();
                        $span->name = 'callback';
                        $span->type = Type::WEB_SERVLET;

                        if (is_array($callback)) {
                            list($class, $method) = $callback;
                            if (is_object($class)) {
                                $class = explode('\\', get_class($class));
                            } else {
                                $class = explode('\\', $class);
                            }
                            $class = end($class);
                            $span->resource = "(callback: $class::$method)";
                        } elseif (is_string($callback)) {
                            $function = explode('\\', $callback);
                            $function = end($function);
                            $span->resource = "(callback: $function)";
                        } else {
                            $span->resource = '(callback: {closure})';
                        }

                        $span->service = $service;
                        $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;

                        $pluginDir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/plugins' : '');
                        if ($pluginDir) {
                            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
                            $file = isset($backtrace[0]['file']) ? $backtrace[0]['file'] : '';
                            if (strpos($file, $pluginDir) === 0) {
                                $plugin = substr($file, strlen($pluginDir) + 1);
                                $plugin = explode('/', $plugin);
                                $plugin = $plugin[0];
                                $span->meta['wp.plugin'] = $plugin;
                            }
                        }

                        remove_hook($hook->id);
                    }
                );
                /*
                if (is_array($callback)) {
                    list($class, $method) = $callback;
                    if (is_object($class)) {
                        $class = get_class($class);
                        Logger::get()->debug("Adding class $class::$method");
                        install_hook(
                            "$class::$method",
                            function (HookData $hook) use ($service, $class, $method) {
                                $span = $hook->span();

                                $span->type = Type::WEB_SERVLET;
                                $class = explode('\\', $class);
                                $class = end($class);
                                $span->name = "callback";
                                $span->resource = "(callback: $class::$method)";

                                $span->service = $service;
                                $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;

                                remove_hook($hook->id);
                            }
                        );
                    }
                } elseif (is_string($callback)) {
                    $function = explode('/', $callback);
                    $function = end($function);
                    //Logger::get()->debug("Adding fn $function");

                    install_hook(
                        $function,
                        function (HookData $hook) use ($service, $function) {
                            $span = $hook->span();

                            $span->type = Type::WEB_SERVLET;
                            $function = explode('\\', $function);
                            $function = end($function);
                            $span->name = "callback";
                            $span->resource = "(callback: $function)";

                            $span->service = $service;
                            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;

                            remove_hook($hook->id);
                        }
                    );
                } else {
                    install_hook(
                        $callback,
                        function (HookData $hook) use ($service) {
                            $span = $hook->span();

                            $span->type = Type::WEB_SERVLET;
                            $span->name = 'callback';
                            $span->resource = "(callback)";

                            $span->service = $service;
                            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;

                            remove_hook($hook->id);
                        }
                    );
                } elseif (is_callable($callback)) {
                    // Retrieve the class + method from the closure, if any
                    $reflection = new \ReflectionFunction($callback);
                    $class = $reflection->getClosureScopeClass();
                    if ($class) {
                        $class = $class->getName();
                        $method = $reflection->getShortName();

                        Logger::get()->debug("(Reflection $action) Adding closure $class::$method");
                        install_hook(
                            $callback,
                            function (HookData $hook) use ($service, $class, $method) {
                                $span = $hook->span();

                                $span->type = Type::WEB_SERVLET;
                                $class = explode('\\', $class);
                                $class = end($class);
                                $span->name = "callback";
                                $span->resource = "(callback: $class::$method)";

                                $span->service = $service;
                                $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;

                                remove_hook($hook->id);
                            }
                        );
                    } else {
                        Logger::get()->debug("(Reflection $action) Adding fn");
                        $reflection = new \ReflectionFunction($callback);
                        $name = $reflection->getClosure();
                        install_hook(
                            $callback,
                            function (HookData $hook) use ($service) {
                                $span = $hook->span();

                                $span->type = Type::WEB_SERVLET;
                                $span->name = "callback";
                                $span->resource = "(callback)";

                                $span->service = $service;
                                $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;

                                remove_hook($hook->id);
                            }
                        );
                    }
                }
                */
            }
        });

        return Integration::LOADED;
    }
}
