<?php

namespace DDTrace\Integrations\WordPress\V4;

use DDTrace\Integrations\WordPress\WordPressIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

class WordPressIntegrationLoader
{
    /**
     * @var SpanData
     */
    public $rootSpan;

    public function load(WordPressIntegration $integration)
    {
        $this->rootSpan = \DDTrace\root_span();
        if (!$this->rootSpan) {
            return Integration::NOT_LOADED;
        }
        // Overwrite the default web integration
        $integration->addTraceAnalyticsIfEnabled($this->rootSpan);
        $this->rootSpan->name = 'wordpress.request';
        $service = \ddtrace_config_app_name(WordPressIntegration::NAME);
        $this->rootSpan->service = $service;
        if ('cli' !== PHP_SAPI) {
            $normalizedPath = \DDtrace\Private_\util_uri_normalize_incoming_path($_SERVER['REQUEST_URI']);
            $this->rootSpan->resource = $_SERVER['REQUEST_METHOD'] . ' ' . $normalizedPath;
            $this->rootSpan->meta[Tag::HTTP_URL] = \DDTrace\Private_\util_url_sanitize(home_url(add_query_arg($_GET)));
        }

        // Core
        \DDTrace\trace_method('WP', 'main', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.main';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_method('WP', 'init', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.init';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_method('WP', 'parse_request', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.parse_request';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_method('WP', 'send_headers', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.send_headers';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_method('WP', 'query_posts', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.query_posts';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_method('WP', 'handle_404', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.handle_404';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_method('WP', 'register_globals', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP.register_globals';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('create_initial_post_types', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'create_initial_post_types';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('create_initial_taxonomies', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'create_initial_taxonomies';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('wp_print_head_scripts', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_print_head_scripts';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });
        \DDTrace\trace_function('wp_print_footer_scripts', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_print_footer_scripts';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        // These not called in PHP 5 due to call_user_func_array() bug
        \DDTrace\trace_function('wp_maybe_load_widgets', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_maybe_load_widgets';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('wp_maybe_load_embeds', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_maybe_load_embeds';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('_wp_customize_include', function (SpanData $span) use ($service) {
            $span->name = $span->resource = '_wp_customize_include';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        // Widgets
        \DDTrace\trace_method('WP_Widget_Factory', '_register_widgets', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP_Widget_Factory._register_widgets';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_method('WP_Widget', 'display_callback', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'WP_Widget.display_callback';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
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
        });

        \DDTrace\trace_method('wpdb', 'query', function (SpanData $span, array $args) use ($service) {
            $span->name = 'wpdb.query';
            $span->resource = $args[0];
            $span->type = Type::SQL;
            $span->service = $service;
        });

        // Views
        \DDTrace\trace_function('get_header', function (SpanData $span, array $args) use ($service) {
            $span->name = 'get_header';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('wp_head', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'wp_head';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('the_custom_header_markup', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'the_custom_header_markup';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('body_class', function (SpanData $span) use ($service) {
            $span->name = $span->resource = 'body_class';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('load_template', function (SpanData $span, array $args) use ($service) {
            $span->name = 'load_template';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('comments_template', function (SpanData $span, array $args) use ($service) {
            $span->name = 'comments_template';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('get_sidebar', function (SpanData $span, array $args) use ($service) {
            $span->name = 'get_sidebar';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('dynamic_sidebar', function (SpanData $span, array $args) use ($service) {
            $span->name = 'dynamic_sidebar';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        \DDTrace\trace_function('get_footer', function (SpanData $span, array $args) use ($service) {
            $span->name = 'get_footer';
            $span->resource = !empty($args[0]) ? $args[0] : $span->name;
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
        });

        return Integration::LOADED;
    }
}
