<?php
// Test endpoint that returns blocking response headers or cookies
// Used by curl tests to trigger AppSec blocking
// Note: This runs on a separate PHP server without ddtrace loaded

if (extension_loaded('ddtrace')) {
    http_response_code(500);
    die("This endpoint should not have ddtrace loaded.");
}

$variant = $_GET['variant'] ?? 'header';

switch ($variant) {
    case 'ping':
        // Health check endpoint
        echo "pong";
        break;
    case 'header':
        header('X-Custom-Header: blocked_response_headers');
        echo "Response with blocking header";
        break;
    case 'cookie':
        header('Set-Cookie: session=blocked_response_cookies');
        echo "Response with blocking cookie";
        break;
    case 'echo':
        $input = file_get_contents('php://input');
        $method = $_SERVER['REQUEST_METHOD'];
        if (!empty($_GET['extra_headers']) && is_array($_GET['extra_headers'])) {
            foreach ($_GET['extra_headers'] as $header_name => $header_value) {
                header($header_name . ': ' . $header_value);
            }
        }
        if (!empty($_SERVER['HTTP_X_CUSTOM_HEADER'])) {
            echo "X-Custom-Header: " . $_SERVER['HTTP_X_CUSTOM_HEADER'] . "\n";
        }
        if (!empty($_COOKIE)) {
            foreach ($_COOKIE as $cookie_name => $cookie_value) {
                echo "Cookie-" . $cookie_name . ": " . $cookie_value . "\n";
            }
        }

        echo $method, ':', $input;
        break;
    default:
        http_response_code(400);
        echo "Unknown variant";
        break;
}
