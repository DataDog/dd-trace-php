<?php

namespace DDTrace\Integrations\Wordpress\OTel;

use DDTrace\Integrations\Integration;
use DDTrace\Integrations\WordPress\WordPressIntegration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;
use DDTrace\Util\Normalizer;
use function DDTrace\trace_function;
use function DDTrace\trace_method;

class OTelIntegrationLoader
{
    public function load(WordPressIntegration $integration)
    {
        $rootSpan = \DDTrace\root_span();
        if (!$rootSpan) {
            return Integration::NOT_LOADED;
        }

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

        trace_method('WP', 'main', function (SpanData $span) use ($service) {
            $span->name = 'WP.main';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        trace_method('WP', 'init', function (SpanData $span) use ($service) {
            $span->name = 'WP.init';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        trace_method('WP', 'parse_request', function (SpanData $span) use ($service) {
            $span->name = 'WP.parse_request';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        trace_method('WP', 'send_headers', function (SpanData $span) use ($service) {
            $span->name = 'WP.send_headers';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        trace_method('WP', 'query_posts', function (SpanData $span) use ($service) {
            $span->name = 'WP.query_posts';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        trace_method('WP', 'handle_404', function (SpanData $span) use ($service) {
            $span->name = 'WP.handle_404';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        trace_method('WP', 'register_globals', function (SpanData $span) use ($service) {
            $span->name = 'WP.register_globals';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        trace_function('get_single_template', function (SpanData $span) use ($service) {
            $span->name = 'get_single_template';
            $span->type = Type::WEB_SERVLET;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        trace_method('wpdb', 'db_connect', function (SpanData $span) use ($service) {
            $span->name = 'wpdb.db_connect';
            $span->type = Type::SQL;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        trace_method('wpdb', 'close', function (SpanData $span) use ($service) {
            $span->name = 'wpdb.close';
            $span->type = Type::SQL;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        trace_method('wpdb', 'query', function (SpanData $span) use ($service) {
            $span->name = 'wpdb.query';
            $span->type = Type::SQL;
            $span->service = $service;
            $span->meta[Tag::COMPONENT] = WordPressIntegration::NAME;
        });

        return Integration::LOADED;
    }
}