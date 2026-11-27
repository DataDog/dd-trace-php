<?php
// Test endpoint that returns blocking response headers or cookies
// Used by curl tests to trigger AppSec blocking
// Note: This runs on a separate PHP server without ddtrace loaded

if (extension_loaded('ddtrace')) {
    http_response_code(500);
    die("This endpoint should not have ddtrace loaded.");
}

// Request log (G10.9): when the server is started with
// -d curl_endpoint.log=<file>, every request that reaches this script appends
// one JSON line to <file>: method, content-length or transfer-encoding, body
// (plus its length and sha1) and the request id (?rid=). The body is stored
// base64-encoded: it is not always valid UTF-8 (multipart, binary), and
// json_encode() would fail on it (PHP before 7.2 has no substitution flag).
// A line is always written, even when the encoding fails (it then has an
// "error" field and no method, which the tests report). A request that is
// only half received (a body aborted by AppSec) never reaches the script and
// leaves no line; the tests tell prevention from detection with it.
function log_request()
{
    $file = get_cfg_var('curl_endpoint.log');
    if (!is_string($file) || $file === '') {
        return;
    }
    $body = file_get_contents('php://input');
    $entry = array(
        'rid' => (string)($_GET['rid'] ?? ''),
        'variant' => (string)($_GET['variant'] ?? ''),
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? null,
        'transfer_encoding' => $_SERVER['HTTP_TRANSFER_ENCODING'] ?? null,
        'body_length' => strlen($body),
        'body_sha1' => sha1($body),
        'body_b64' => base64_encode($body),
    );
    unset($body);
    $line = json_encode($entry);
    if ($line === false) {
        // the uri or a header is not valid UTF-8
        $line = json_encode(array(
            'rid' => (string)($_GET['rid'] ?? ''),
            'error' => 'json_encode failed: ' . json_last_error_msg(),
        ));
    }
    file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
}
log_request();

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

function generate_body_over_limit_pattern_first($blocking_pattern = 'blocked_response_body') : string
{
    $limit = 524288;
    return json_encode(array(
        'key' => $blocking_pattern,  // This comes before the truncation point
        'padding' => str_repeat('a', $limit + 5000)
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
    case 'large_response_over_limit_pattern_first':
        // return a JSON response over the 512KB limit with blocking pattern before truncation
        header('Content-Type: application/json');
        echo generate_body_over_limit_pattern_first();
        break;
    case 'status':
        // a response with the status given in code (a teapot by default)
        http_response_code(intval($_GET['code'] ?? '418'));
        echo "Response with a chosen status";
        break;
    case 'xml_response':
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><root><key>blocked_response_body</key></root>';
        break;
    case 'forward':
        $code = intval($_GET['code'] ?? '302');
        $hops = intval($_GET['hops'] ?? '0');
        $final_path = $_GET['final_path'] ?? '/example.json';

        // Support path_pattern to construct paths server-side (to avoid WAF detection in query string).
        // redirect_check=url makes the resolved URL match rule CUSTOM-008.
        if (isset($_GET['path_pattern'])) {
            switch ($_GET['path_pattern']) {
                case 'relative_parent':
                    $final_path = 'a/../example.html?redirect_check=url';
                    break;
                case 'absolute_parent':
                    $final_path = '/a/../example.html?redirect_check=url';
                    break;
                case 'double_slash':
                    $final_path = './/example.html?redirect_check=url';
                    break;
                case 'query_only':
                    $final_path = '?variant=ping&redirect_check=url';
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
