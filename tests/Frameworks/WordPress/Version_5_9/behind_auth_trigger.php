<?php
ob_start();
require __DIR__ . '/wp-load.php';
ob_end_clean();

// Resolve admin user (created in @BeforeAll via `wp core install`).
$user = get_user_by('login', 'admin');
if (!$user) {
    http_response_code(500);
    echo 'admin user missing';
    return;
}

// Set current user, then exercise the wp_validate_auth_cookie hook, which is
// where the integration emits track_authenticated_user_event_automated.
wp_set_current_user($user->ID);
$cookie = wp_generate_auth_cookie($user->ID, time() + 3600, 'logged_in');
$validated = wp_validate_auth_cookie($cookie, 'logged_in');

echo 'validated: ' . ($validated ? (int) $validated : 'false');
