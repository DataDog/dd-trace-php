<?php

namespace DDTrace\Integrations\WordPress\V6;

use DDTrace\Integrations\WordPress\WordPressIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;

class WordPressComponent
{
    const COMPONENT_NAME = WordPressIntegration::NAME;

    private $service;

    /**
     * @param string $service
     */
    public function __construct($service)
    {
        $this->service = $service;
    }

    /**
     * @param string $class
     * @param string $method
     * @param \Closure $closure
     * @return bool
     */
    public function traceMethod($class, $method, \Closure $closure)
    {
        $library = $this;
        return \DDTrace\trace_method(
            $class,
            $method,
            [
                'recurse' => true,
                'prehook' => function (SpanData $span, ...$args) use ($closure, $library) {
                    // These are override-able by the closure.
                    $span->type = Type::WEB_SERVLET;

                    $return = $closure($span, ...$args);

                    // Shouldn't be override-able.
                    $span->service = $library->service;
                    $span->meta[Tag::COMPONENT] = $library::COMPONENT_NAME;

                    return $return;
                },
            ]
        );
    }

    /**
     * @param string $function
     * @param \Closure $closure
     * @return bool
     */
    public function traceFunction($function, \Closure $closure)
    {
        $library = $this;
        return \DDTrace\trace_function(
            $function,
            [
                'recurse' => true,
                'prehook' => function (SpanData $span, ...$args) use ($closure, $library) {
                    // These are override-able by the closure.
                    $span->type = Type::WEB_SERVLET;

                    $return = $closure($span, ...$args);

                    // Shouldn't be override-able.
                    $span->service = $library->service;
                    $span->meta[Tag::COMPONENT] = $library::COMPONENT_NAME;

                    return $return;
                },
            ]
        );
    }

    public function load(WordPressIntegration $integration)
    {
        $rootSpan = \DDTrace\root_span();
        if (!$rootSpan) {
            return Integration::NOT_LOADED;
        }

        $library = $this;

        // Overwrite the default web integration
        $integration->addTraceAnalyticsIfEnabled($rootSpan);
        $rootSpan->name = 'wordpress.request';
        $rootSpan->service = $library->service;

        if ('cli' !== \PHP_SAPI) {
            $normalizedPath = Normalizer::uriNormalizeincomingPath($_SERVER['REQUEST_URI']);
            $rootSpan->resource = $_SERVER['REQUEST_METHOD'] . ' ' . $normalizedPath;
            \DDTrace\hook_function('wp_plugin_directory_constants', function () use ($rootSpan) {
                if (!array_key_exists(Tag::HTTP_URL, $rootSpan->meta)) {
                    $rootSpan->meta[Tag::HTTP_URL] = Normalizer::urlSanitize(home_url(add_query_arg($_GET)));
                }
            });
        }

        // Core
        $library->traceMethod('WP', 'main', function (SpanData $span) {
            $span->name = 'WP.main';
        });

        $library->traceMethod('WP', 'init', function (SpanData $span) {
            $span->name = 'WP.init';
        });

        $library->traceMethod('WP', 'parse_request', function (SpanData $span) {
            $span->name = 'WP.parse_request';
        });

        $library->traceMethod('WP', 'send_headers', function (SpanData $span) {
            $span->name = 'WP.send_headers';
        });

        $library->traceMethod('WP', 'query_posts', function (SpanData $span) {
            $span->name = 'WP.query_posts';
        });

        $library->traceMethod('WP', 'handle_404', function (SpanData $span) {
            $span->name = 'WP.handle_404';
        });

        $library->traceMethod('WP', 'register_globals', function (SpanData $span) {
            $span->name = 'WP.register_globals';
        });

        $library->traceFunction('create_initial_post_types', function (SpanData $span) {
        });

        $library->traceFunction('create_initial_taxonomies', function (SpanData $span) {
        });

        $library->traceFunction('wp_print_head_scripts', function (SpanData $span) {
        });

        $library->traceFunction('wp_print_footer_scripts', function (SpanData $span) {
        });

        $library->traceFunction('wp_maybe_load_widgets', function (SpanData $span) {
        });

        $library->traceFunction('wp_maybe_load_embeds', function (SpanData $span) {
        });

        $library->traceFunction('_wp_customize_include', function (SpanData $span) {
        });

        // Blocks
        $library->traceFunction('render_block', function (SpanData $span, array $args) {
            $span->name = 'block';
            $blockName = isset($args[0]['blockName']) ? $args[0]['blockName'] : '?';
            $span->resource = "(name: $blockName)";
        });

        // Widgets
        $library->traceFunction('wp_widgets_init', function (SpanData $span) {
        });

        /* When a widget is registered, trace its `widget` method. The base
         * method, WP_Widget::widget, is not called, so we cannot intercept it
         * generically.
         *
         * Widgets have largely been replaced by blocks in Wordpress 6.
         */
        \DDTrace\hook_function('register_widget', function ($args) use ($library) {
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

            $library->traceMethod($className, 'widget', function (SpanData $span) {
                $span->name = 'widget';
                // WP_Widgets have a public $name property.
                $name = isset($this->name) ? $this->name : '?';
                $span->resource = "(name: $name)";
            });
        });

        // Database
        $library->traceMethod('wpdb', '__construct', function (SpanData $span, array $args) {
            $span->name = $span->resource = 'wpdb.__construct';
            $span->type = Type::SQL;
            $span->meta = [
                'db.user' => $args[0],
                'db.name' => $args[2],
                'db.host' => $args[3],
            ];
        });

        $library->traceMethod('wpdb', 'query', function (SpanData $span, array $args) {
            $span->name = 'wpdb.query';
            $span->resource = $args[0];
            $span->type = Type::SQL;
        });

        // Views
        $library->traceFunction('get_header', function (SpanData $span, array $args) {
            $span->name = 'get_header';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
        });

        $library->traceFunction('the_custom_header_markup', function (SpanData $span) {
        });

        // ???
        $library->traceFunction('body_class', function (SpanData $span) {
        });

        $library->traceFunction('load_template', function (SpanData $span, array $args) {
            if (!empty($args[0])) {
                $span->resource = $args[0];
            }
        });

        $library->traceFunction('the_content', function (SpanData $span) {
        });

        $library->traceFunction('the_post', function (SpanData $span) {
        });

        $library->traceFunction('get_avatar', function (SpanData $span) {
        });

        $library->traceFunction('the_post_thumbnail', function (SpanData $span, array $args) {
            // might also be an array an integers
            if (isset($args[0]) && \is_string($args[0])) {
                $size = $args[0];
                $span->meta['wordpress.post_thumbnail.size'] = $size;
            }
        });

        $library->traceFunction('comments_template', function (SpanData $span, array $args) {
            $span->name = 'comments_template';
            $span->resource = !empty($args[0]) ? $args[0] : '/comments.php';
        });

        // ???
        $library->traceFunction('get_sidebar', function (SpanData $span, array $args) {
            if (!empty($args[0])) {
                $span->resource = $args[0];
            }
        });

        /* The dynamic sidebar has many actions which trigger inside it, so
         * make a span to group these operations.
         */
        $library->traceFunction('dynamic_sidebar', function (SpanData $span, array $args) {
            if (!isset($args[0])) {
                $index = $args[0];
                $span->resource = \is_int($index) ? "sidebar-$index" : $index;
            }
        });

        /* The footer will perform action `get_footer` but also loads 1 or more
         * templates, so make a span to group these operations.
         */
        $library->traceFunction('get_footer', function () {
        });

        // https://developer.wordpress.org/reference/functions/get_query_template/
        $library->traceFunction('get_query_template', function (SpanData $span, array $args) {
            $type = isset($args[0]) ? $args[0] : '?';
            $span->resource = "(type: $type)";
        });

        // Actions
        // https://developer.wordpress.org/reference/functions/do_action/
        $action = function (SpanData $span, array $args) {
            $span->name = 'action';
            $hookName = isset($args[0]) ? $args[0] : '?';
            $span->resource = "(hook_name: $hookName)";
        };
        foreach (['do_action', 'do_action_ref_array'] as $function) {
            $library->traceFunction($function, $action);
        }

        return Integration::LOADED;
    }
}
