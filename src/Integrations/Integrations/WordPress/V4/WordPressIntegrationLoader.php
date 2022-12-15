<?php

namespace DDTrace\Integrations\WordPress\V4;

use DDTrace\Integrations\WordPress\WordPressIntegration;
use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use Illuminate\Support\Str;

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
        $rootSpan->meta[Tag::COMPONENT] = $this->getName();
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
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'WP.main',
                Type::WEB_SERVLET,
                $service,
                'WP.main'
            );
        });

        \DDTrace\trace_method('WP', 'init', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'WP.init',
                Type::WEB_SERVLET,
                $service,
                'WP.init'
            );
        });

        \DDTrace\trace_method('WP', 'parse_request', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'WP.parse_request',
                Type::WEB_SERVLET,
                $service,
                'WP.parse_request'
            );
        });

        \DDTrace\trace_method('WP', 'send_headers', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'WP.send_headers',
                Type::WEB_SERVLET,
                $service,
                'WP.send_headers'
            );
        });

        \DDTrace\trace_method('WP', 'query_posts', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'WP.query_posts',
                Type::WEB_SERVLET,
                $service,
                'WP.query_posts'
            );
        });

        \DDTrace\trace_method('WP', 'handle_404', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'WP.handle_404',
                Type::WEB_SERVLET,
                $service,
                'WP.handle_404'
            );
        });

        \DDTrace\trace_method('WP', 'register_globals', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'WP.register_globals',
                Type::WEB_SERVLET,
                $service,
                'WP.register_globals'
            );
        });

        \DDTrace\trace_function('create_initial_post_types', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'create_initial_post_types',
                Type::WEB_SERVLET,
                $service,
                'create_initial_post_types'
            );
        });

        \DDTrace\trace_function('create_initial_taxonomies', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'create_initial_taxonomies',
                Type::WEB_SERVLET,
                $service,
                'create_initial_taxonomies'
            );
        });

        \DDTrace\trace_function('wp_print_head_scripts', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'wp_print_head_scripts',
                Type::WEB_SERVLET,
                $service,
                'wp_print_head_scripts'
            );
        });
        \DDTrace\trace_function('wp_print_footer_scripts', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'wp_print_footer_scripts',
                Type::WEB_SERVLET,
                $service,
                'wp_print_footer_scripts'
            );
        });

        // These not called in PHP 5 due to call_user_func_array() bug
        \DDTrace\trace_function('wp_maybe_load_widgets', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'wp_maybe_load_widgets',
                Type::WEB_SERVLET,
                $service,
                'wp_maybe_load_widgets'
            );
        });

        \DDTrace\trace_function('wp_maybe_load_embeds', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'wp_maybe_load_embeds',
                Type::WEB_SERVLET,
                $service,
                'wp_maybe_load_embeds'
            );
        });

        \DDTrace\trace_function('_wp_customize_include', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                '_wp_customize_include',
                Type::WEB_SERVLET,
                $service,
                '_wp_customize_include'
            );
        });

        // Widgets
        \DDTrace\trace_method('WP_Widget_Factory', '_register_widgets', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'WP_Widget_Factory._register_widgets',
                Type::WEB_SERVLET,
                $service,
                'WP_Widget_Factory._register_widgets'
            );
        });

        \DDTrace\trace_method('WP_Widget', 'display_callback', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'WP_Widget.display_callback',
                Type::WEB_SERVLET,
                $service,
                'WP_Widget.display_callback'
            );
        });

        // Database
        \DDTrace\trace_method('wpdb', '__construct', function (SpanData $span, array $args) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'wpdb.__construct',
                Type::SQL,
                $service,
                'wpdb.__construct',
                [
                    'db.user' => $args[0],
                    'db.name' => $args[2],
                    'db.host' => $args[3],
                ],
            );
        });

        \DDTrace\trace_method('wpdb', 'query', function (SpanData $span, array $args) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'wpdb.query',
                Type::SQL,
                $service,
                $args[0]
            );
        });

        // Views
        \DDTrace\trace_function('get_header', function (SpanData $span, array $args) use ($service) {
            $resource = !empty($args[0]) ? $args[0] : 'get_header';
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'get_header',
                Type::WEB_SERVLET,
                $service,
                $resource
            );
        });

        \DDTrace\trace_function('wp_head', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'wp_head',
                Type::WEB_SERVLET,
                $service,
                'wp_head'
            );
        });

        \DDTrace\trace_function('the_custom_header_markup', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'the_custom_header_markup',
                Type::WEB_SERVLET,
                $service,
                'the_custom_header_markup'
            );
        });

        \DDTrace\trace_function('body_class', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'body_class',
                Type::WEB_SERVLET,
                $service,
                'body_class'
            );
        });

        \DDTrace\trace_function('load_template', function (SpanData $span, array $args) use ($service) {
            $resource = !empty($args[0]) ? $args[0] : 'load_template';
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'load_template',
                Type::WEB_SERVLET,
                $service,
                $resource
            );
        });

        \DDTrace\trace_function('comments_template', function (SpanData $span, array $args) use ($service) {
            $resource = !empty($args[0]) ? $args[0] : 'comments_template';
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'comments_template',
                Type::WEB_SERVLET,
                $service,
                $resource
            );
        });

        \DDTrace\trace_function('get_sidebar', function (SpanData $span, array $args) use ($service) {
            $resource = !empty($args[0]) ? $args[0] : 'get_sidebar';
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'get_sidebar',
                Type::WEB_SERVLET,
                $service,
                $resource
            );
        });

        \DDTrace\trace_function('dynamic_sidebar', function (SpanData $span, array $args) use ($service) {
            $resource = !empty($args[0]) ? $args[0] : 'dynamic_sidebar';
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'dynamic_sidebar',
                Type::WEB_SERVLET,
                $service,
                $resource
            );
        });

        \DDTrace\trace_function('get_footer', function (SpanData $span, array $args) use ($service) {
            $resource = !empty($args[0]) ? $args[0] : 'get_footer';
            WordPressIntegrationLoader::setCommonValues(
                $span,
                'get_footer',
                Type::WEB_SERVLET,
                $service,
                $resource
            );
        });

        return Integration::LOADED;
    }

    public function setCommonValues(SpanData $span, string $name, string $type, string $service, string $resource = null, array $meta = array())
    {
        $span->type = $type;
        $span->name = $name;
        $span->meta = $meta;
        $span->meta[Tag::COMPONENT] = $this->getName();
        if ($resource) {
            $span->resource = $resource;
        }
    }
}
