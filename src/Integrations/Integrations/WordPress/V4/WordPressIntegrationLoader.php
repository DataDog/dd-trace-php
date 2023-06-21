<?php

namespace DDTrace\Integrations\WordPress\V4;

use DDTrace\Integrations\WordPress\WordPressIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\Log\Logger;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use function DDTrace\trace_function;

class WordPressIntegrationLoader
{
    public function load(WordPressIntegration $integration)
    {
        Logger::get()->debug('Loading WordPress integration');
        $rootSpan = \DDTrace\root_span();
        if (!$rootSpan) {
            return Integration::NOT_LOADED;
        }
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
        \DDTrace\trace_function('wp_print_footer_scripts', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_print_footer_scripts';
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
        \DDTrace\trace_method('WP_Widget_Factory', '_register_widgets', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP_Widget_Factory._register_widgets';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('WP_Widget', 'display_callback', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP_Widget.display_callback';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        // Database
        \DDTrace\trace_method('wpdb', '__construct', function (SpanData $span, array $args) use ($service) {
            $span->name = $span->resource = 'wpdb.__construct';
            $span->type = Type::SQL;
            $span->service = $service;
            $span->meta = [
                'db.user' => $args[0],
                'db.name' => $args[2],
                'db.host' => $args[3],
            ];
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
            $span->meta[Tag::DB_SYSTEM] = "mysql";
        });

        \DDTrace\trace_method('wpdb', 'query', function (SpanData $span, array $args) use ($service) {
            $span->name = 'wpdb.query';
            $span->resource = $args[0];
            $span->type = Type::SQL;
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

        \DDTrace\trace_function('wp_head', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_head';
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
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
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

        $hookToPlugin = [];

        $action = function (SpanData $span, $args) use (&$hookToPlugin) {
            $span->name = 'action';
            $hookName = isset($args[0]) ? $args[0] : '?';
            $span->resource = "(hook_name: $hookName)";

            // If we have a plugin name, add it to the meta
            if (isset($hookToPlugin[$hookName]) && $hookToPlugin[$hookName]) {
                Logger::get()->debug("Using plugin for hook $hookName: " . $hookToPlugin[$hookName]);
                $span->meta['wp.plugin'] = $hookToPlugin[$hookName];
            }

            // Try to find the plugin associated to the hook
            // 1. Retrieve the plugin dir (WP_PLUGIN_DIR, else WP_CONTENT_DIR . '/' plugins, else nothing)
            $pluginDir = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/plugins' : '');
            Logger::get()->debug("Plugin dir: $pluginDir");
            // 2. Get the path of the file that contains the hook
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $file = isset($backtrace[2]['file']) ? $backtrace[2]['file'] : '';
            Logger::get()->debug("File: $file");
            // 3. If the file can be prefixed by the plugin dir, we have a winner
            if (strpos($file, $pluginDir) === 0) {
                // The plugin name will be what follows the plugin dir
                // Format: <plugin_dir>/<plugin_name>/... or <plugin_dir>/<plugin_name>.php
                $pluginName = substr($file, strlen($pluginDir) + 1);
                $pluginName = explode('/', $pluginName)[0];
                $pluginName = explode('.', $pluginName)[0];
                $span->meta['wp.plugin'] = $pluginName;
                Logger::get()->debug("Found plugin $pluginName for hook $hookName");
                $hookToPlugin[$hookName] = $pluginName;
            }
        };

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
                    }
                ]
            );
        }

        return Integration::LOADED;
    }
}
