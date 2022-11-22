<?php

    namespace DDTrace\Integrations\WordPress\V6;

use DDTrace\Integrations\WordPress\WordPressIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;

class WordPressIntegrationLoader
{
    public function load(WordPressIntegration $integration)
    {
        $rootSpan = \DDTrace\root_span();
        if (!$rootSpan) {
            return Integration::NOT_LOADED;
        }
        // Overwrite the default web integration
        $integration->addTraceAnalyticsIfEnabled($rootSpan);
        $rootSpan->name = 'wordpress.request';
        $service = \ddtrace_config_app_name(WordPressIntegration::NAME);
        $rootSpan->service = $service;
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
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('WP', 'init', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.init';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('WP', 'parse_request', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.parse_request';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('WP', 'send_headers', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.send_headers';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('WP', 'query_posts', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.query_posts';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('WP', 'handle_404', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.handle_404';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('WP', 'register_globals', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.register_globals';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('create_initial_post_types', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'create_initial_post_types';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('create_initial_taxonomies', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'create_initial_taxonomies';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('wp_print_head_scripts', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_print_head_scripts';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });
        \DDTrace\trace_function('wp_print_footer_scripts', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_print_footer_scripts';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        // These not called in PHP 5 due to call_user_func_array() bug
        \DDTrace\trace_function('wp_maybe_load_widgets', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_maybe_load_widgets';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('wp_maybe_load_embeds', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_maybe_load_embeds';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('_wp_customize_include', function (SpanData $span) use ($service) {
            $span->name = $span->resource = '_wp_customize_include';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        // Widgets
        \DDTrace\trace_function('wp_widgets_init', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_widgets_init';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        /* When a widget is registered, trace its `widget` method. The base
         * method, WP_Widget::widget, is not called, so we cannot intercept it
         * generically.
         */
        \DDTrace\hook_function('register_widget', function ($args) use ($service) {
            if (!isset($args[0])) {
                return;
            }
            // Signature: register_widget(string|WP_Widget $widget): void
            $widget = $args[0];
            if (\is_string($widget)) {
                $className = $widget;
            } elseif (\is_object($widget)) {
                $className = \get_class($widget);
            } else {
                return;
            }

            \DDTrace\trace_method($className, 'widget', function (SpanData $span) use ($service) {
                $span->name = 'wp.widget';
                // WP_Widgets have a public $name property.
                $span->resource = isset($this->name) ? $this->name : $span->name;
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->meta['component'] = WordPressIntegration::NAME;
            });
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
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_method('wpdb', 'query', function (SpanData $span, array $args) use ($service) {
            $span->name = 'wpdb.query';
            $span->resource = $args[0];
            $span->type = Type::SQL;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        // Views
        \DDTrace\trace_function('get_header', function (SpanData $span, array $args) use ($service) {
            $span->name = 'get_header';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('the_custom_header_markup', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'the_custom_header_markup';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        // ???
        \DDTrace\trace_function('body_class', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'body_class';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('load_template', function (SpanData $span, array $args) use ($service) {
            $span->name = 'load_template';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('the_content', function (SpanData $span) use ($service) {
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('the_post', function (SpanData $span) use ($service) {
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('get_avatar', function (SpanData $span) use ($service) {
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('the_post_thumbnail', function (SpanData $span, array $args) use ($service) {
            // might also be an array an integers
            if (isset($args[0]) && \is_string($args[0])) {
                $size = $args[0];
                $span->meta['wordpress.post_thumbnail.size'] = $size;
            }
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        \DDTrace\trace_function('comments_template', function (SpanData $span, array $args) use ($service) {
            $span->name = 'comments_template';
            $span->resource = !empty($args[0]) ? $args[0] : '/comments.php';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        // ???
        \DDTrace\trace_function('get_sidebar', function (SpanData $span, array $args) use ($service) {
            $span->name = 'get_sidebar';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        /* The dynamic sidebar has many actions which trigger inside it, so
         * make a span to group these operations.
         */
        \DDTrace\trace_function('dynamic_sidebar', [
            'recurse' => true,
            'prehook' => function (SpanData $span, array $args) use ($service) {
                $span->name = 'dynamic_sidebar';
                if (!isset($args[0])) {
                    $index = $args[0];
                    $span->resource = \is_int($index) ? "sidebar-$index" : $index;
                }
                $span->type = Type::WEB_SERVLET;
                $span->service = $service;
                $span->meta['component'] = WordPressIntegration::NAME;
            },
        ]);

        /* The footer will perform action `get_footer` but also loads 1 or more
         * templates, so make a span to group these operations.
         */
        \DDTrace\trace_function('get_footer', function (SpanData $span, array $args) use ($service) {
            $span->name = 'get_footer';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        // https://developer.wordpress.org/reference/functions/get_query_template/
        \DDTrace\trace_function('get_query_template', function (SpanData $span, array $args) use ($service) {
            $type = isset($args[0]) ? $args[0] : '?';
            $span->resource = "(type: $type)";
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        });

        // Actions
        // https://developer.wordpress.org/reference/functions/do_action/
        $action = function (SpanData $span, array $args) use ($service) {
            $span->name = 'action';
            $hookName = isset($args[0]) ? $args[0] : '?';
            $span->resource = "(hook_name: $hookName)";
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta['component'] = WordPressIntegration::NAME;
        };
        foreach (['do_action', 'do_action_ref_array'] as $function) {
            \DDTrace\trace_function($function, ['recurse' => true, 'prehook' => $action]);
        }

        // If we make spans for filters, there will be too many spans.
        // todo: can we make metrics for these instead?
//        $filter = function (SpanData $span, array $args) use ($service) {
//            $span->name = 'wp.apply_filters';
//            if (isset($args[0])) {
//                $span->resource = (string) $args[0];
//            }
//            $span->type = Type::WEB_SERVLET;
//            $span->service = $service;
//        };
//        foreach (['apply_filters', 'apply_filters_ref_array'] as $function) {
//            \DDTrace\trace_function($function, ['recurse' => true, 'prehook' => $filter]);
//        }

        return Integration::LOADED;
    }
}
