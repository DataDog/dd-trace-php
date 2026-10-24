<?php
ob_start();
require __DIR__ . '/wp-load.php';
require_once ABSPATH . 'wp-includes/user.php';
ob_end_clean();

$login = $_GET['login'] ?? 'newuser';
$email = $_GET['email'] ?? 'newuser@example.com';

// Direct call to bypass the email/multisite logic of the registration form.
// This still triggers our hook on `register_new_user` (the function call), so
// the integration's `track_user_signup_event_automated` is exercised.
$result = register_new_user($login, $email);

if (is_wp_error($result)) {
    echo 'Error: ' . $result->get_error_code();
} else {
    echo 'Created user id: ' . (int) $result;
}
