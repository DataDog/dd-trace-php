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
        $rootSpan->meta[Tag::COMPONENT] = Integration::getName();
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
                name: 'WP.main',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'WP.main'
            );
        });

        \DDTrace\trace_method('WP', 'init', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'WP.init',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'WP.init'
            );
        });

        \DDTrace\trace_method('WP', 'parse_request', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'WP.parse_request',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'WP.parse_request'
            );
        });

        \DDTrace\trace_method('WP', 'send_headers', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'WP.send_headers',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'WP.send_headers'
            );
        });

        \DDTrace\trace_method('WP', 'query_posts', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'WP.query_posts',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'WP.query_posts'
            );
        });

        \DDTrace\trace_method('WP', 'handle_404', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'WP.handle_404',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'WP.handle_404'
            );
        });

        \DDTrace\trace_method('WP', 'register_globals', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'WP.register_globals',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'WP.register_globals'
            );
        });

        \DDTrace\trace_function('create_initial_post_types', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'create_initial_post_types',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'create_initial_post_types'
            );
        });

        \DDTrace\trace_function('create_initial_taxonomies', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'create_initial_taxonomies',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'create_initial_taxonomies'
            );
        });

        \DDTrace\trace_function('wp_print_head_scripts', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'wp_print_head_scripts',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'wp_print_head_scripts'
            );
        });
        \DDTrace\trace_function('wp_print_footer_scripts', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'wp_print_footer_scripts',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'wp_print_footer_scripts'
            );
        });

        // These not called in PHP 5 due to call_user_func_array() bug
        \DDTrace\trace_function('wp_maybe_load_widgets', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'wp_maybe_load_widgets',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'wp_maybe_load_widgets'
            );
        });

        \DDTrace\trace_function('wp_maybe_load_embeds', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'wp_maybe_load_embeds',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'wp_maybe_load_embeds'
            );
        });

        \DDTrace\trace_function('_wp_customize_include', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: '_wp_customize_include',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: '_wp_customize_include'
            );
        });

        // Widgets
        \DDTrace\trace_method('WP_Widget_Factory', '_register_widgets', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'WP_Widget_Factory._register_widgets',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'WP_Widget_Factory._register_widgets'
            );
        });

        \DDTrace\trace_method('WP_Widget', 'display_callback', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'WP_Widget.display_callback',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'WP_Widget.display_callback'
            );
        });

        // Database
        \DDTrace\trace_method('wpdb', '__construct', function (SpanData $span, array $args) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'wpdb.__construct',
                type: Type::SQL,
                service: $service,
                resource: 'wpdb.__construct',
                meta: [
                    'db.user' => $args[0],
                    'db.name' => $args[2],
                    'db.host' => $args[3],
                ]
            );
        });

        \DDTrace\trace_method('wpdb', 'query', function (SpanData $span, array $args) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'wpdb.query',
                type: Type::SQL,
                service: $service,
                resource: $args[0]
            );
        });

        // Views
        \DDTrace\trace_function('get_header', function (SpanData $span, array $args) use ($service) {
            $resource = !empty($args[0]) ? $args[0] : 'get_header';
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'get_header',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: $resource
            );
        });

        \DDTrace\trace_function('wp_head', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'wp_head',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'wp_head'
            );
        });

        \DDTrace\trace_function('the_custom_header_markup', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'the_custom_header_markup',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'the_custom_header_markup'
            );
        });

        \DDTrace\trace_function('body_class', function (SpanData $span) use ($service) {
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'body_class',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: 'body_class'
            );
        });

        \DDTrace\trace_function('load_template', function (SpanData $span, array $args) use ($service) {
            $resource = !empty($args[0]) ? $args[0] : 'load_template';
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'load_template',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: $resource
            );
        });

        \DDTrace\trace_function('comments_template', function (SpanData $span, array $args) use ($service) {
            $resource = !empty($args[0]) ? $args[0] : 'comments_template';
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'comments_template',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: $resource
            );
        });

        \DDTrace\trace_function('get_sidebar', function (SpanData $span, array $args) use ($service) {
            $resource = !empty($args[0]) ? $args[0] : 'get_sidebar';
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'get_sidebar',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: $resource
            );
        });

        \DDTrace\trace_function('dynamic_sidebar', function (SpanData $span, array $args) use ($service) {
            $resource = !empty($args[0]) ? $args[0] : 'dynamic_sidebar';
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'dynamic_sidebar',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: $resource
            );
        });

        \DDTrace\trace_function('get_footer', function (SpanData $span, array $args) use ($service) {
            $resource = !empty($args[0]) ? $args[0] : 'get_footer';
            WordPressIntegrationLoader::setCommonValues(
                $span,
                name: 'get_footer',
                type: Type::WEB_SERVLET,
                service: $service,
                resource: $resource
            );
        });

        return Integration::LOADED;
    }

    public function setCommonValues(SpanData $span, string $name, string $type, string $service, string $resource = null, array $meta = array())
    {
        $span->type = $type;
        $span->name = $name;
        $span->meta = $meta;
        $span->meta[Tag::COMPONENT] = Integration::getName();
        if ($resource) {
            $span->resource = $resource;
        }
    }
}
