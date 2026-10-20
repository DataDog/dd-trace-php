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
    add_rewrite_rule(
        '^normalized-literal/file\.json$',
        'index.php?normalized_route_test=1&normalized_value=literal',
        'top'
    );
    add_rewrite_rule(
        '^normalized-capture-hole/(?:([^/]+)-)?([^/]+)$',
        'index.php?normalized_route_test=1&normalized_value=$matches[2]',
        'top'
    );
    add_rewrite_rule(
        '^normalized-named-captures/(?P<first>[^/]+)-(?P<second>[^/]+)/?$',
        'index.php?normalized_route_test=1&normalized_value=$matches[1]-$matches[2]',
        'top'
    );
    // WordPress prepends the requested file (index.php) to PATH_INFO once a
    // rule starts with it, and keeps doing so for every later rule. This rule
    // never matches; it only switches the subject of the next one.
    add_rewrite_rule(
        'index.php/normalized-pathinfo-anchor$',
        'index.php?normalized_route_test=1&normalized_value=anchor',
        'top'
    );
    add_rewrite_rule(
        '(?:([^/]+)/)?normalized-pathinfo/([^/]+)/?$',
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
    // Before redirect_canonical, which would strip /index.php/ with a 301.
}, 0);
