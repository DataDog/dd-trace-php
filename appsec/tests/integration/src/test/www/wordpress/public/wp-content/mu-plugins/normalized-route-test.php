<?php

/*
 * Plugin Name: Normalized route integration fixture
 */

add_action('init', static function () {
    add_rewrite_rule(
        '^normalized-cache(?:/([^/]+))?/?$',
        'index.php?normalized_route_test=1&normalized_value=$matches[1]',
        'top'
    );
    add_rewrite_rule(
        '^normalized-cache-shape/?([^/]*)/?$',
        'index.php?normalized_route_test=1&normalized_value=$matches[1]',
        'top'
    );
});

add_filter('query_vars', static function (array $queryVars): array {
    $queryVars[] = 'normalized_route_test';
    $queryVars[] = 'normalized_value';
    return $queryVars;
});

add_filter('pre_handle_404', static function ($preempt, $query) {
    if ($query->get('normalized_route_test')) {
        return true;
    }
    return $preempt;
}, 10, 2);

add_action('template_redirect', static function () {
    if (! get_query_var('normalized_route_test')) {
        return;
    }

    status_header(200);
    header('Content-Type: text/plain');
    echo get_query_var('normalized_value') ?: 'absent';
    exit;
});
