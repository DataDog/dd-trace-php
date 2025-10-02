<?php
// Test endpoint that returns blocking response headers or cookies
// Used by curl tests to trigger AppSec blocking
// Note: This runs on a separate PHP server without ddtrace loaded

if (extension_loaded('ddtrace')) {
    http_response_code(500);
    die("This endpoint should not have ddtrace loaded.");
}

function generate_body_under_limit($blocking_pattern = 'blocked_response_body') : string
{
    $limit = 524288;
    $json_overhead = strlen('{"key":"","padding":""}') + strlen($blocking_pattern);
    $padding_size = $limit - $json_overhead - 100; // 100 byte safety margin
    return json_encode(array(
        'key' => $blocking_pattern,
        'padding' => str_repeat('a', $padding_size)
    ));
}

function generate_body_over_limit($blocking_pattern = 'blocked_response_body') : string
{
    $limit = 524288;
    $padding_size = $limit + 5000;
    return json_encode(array(
        'padding' => str_repeat('a', $padding_size),
        'key' => $blocking_pattern  // This comes after truncation point
    ));
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
    case 'large_response_under_limit':
        // return a JSON response just under the 512KB limit with blocking pattern
        header('Content-Type: application/json');
        echo generate_body_under_limit();
        break;
    case 'large_response_over_limit':
        // return a JSON response over the 512KB limit with blocking pattern beyond truncation
        header('Content-Type: application/json');
        echo generate_body_over_limit();
        break;
    case 'forward':
        $code = intval($_GET['code'] ?? '302');
        $hops = intval($_GET['hops'] ?? '0');
        $final_path = $_GET['final_path'] ?? '/example.json';

        // Support path_pattern to construct paths server-side (to avoid WAF detection in query string)
        if (isset($_GET['path_pattern'])) {
            switch ($_GET['path_pattern']) {
                case 'relative_parent':
                    $final_path = 'a/../example.html';
                    break;
                case 'absolute_parent':
                    $final_path = '/a/../example.html';
                    break;
                case 'double_slash':
                    $final_path = './/example.html';
                    break;
            }
        }

        http_response_code($code);
        if ($hops == 0) {
            header("Location: $final_path", true, $code);
            echo "Final redirect to $final_path with code $code";
        } else {
            // Build redirect URL preserving parameter order
            if (isset($_GET['path_pattern'])) {
                $p = array(
                    'code' => $code,
                    'hops' => $hops - 1,
                    'path_pattern' => $_GET['path_pattern'],
                    'variant' => 'forward',
                );
            } else {
                $p = array(
                    'code' => $code,
                    'hops' => $hops - 1,
                    'final_path' => $final_path,
                    'variant' => 'forward',
                );
            }
            $next_url = 'http://' . $_SERVER['HTTP_HOST'] . '/curl_requests_endpoint.php?'
                . http_build_query($p);

            header("Location: $next_url", true, $code);
            echo "Redirecting to $next_url with $code";
        }

        break;
    default:
        http_response_code(400);
        echo "Unknown variant";
        break;
}
