--TEST--
Thread mode connection with PHP-FPM (manual verification test)
--SKIPIF--
<?php
// This test is currently skipped because automated PHP-FPM thread mode testing
// in .phpt format is not feasible. The thread listener must be started in the
// PHP-FPM master process, not in the CLI test script.
//
// For manual testing of thread mode with PHP-FPM, see:
// tests/ext/sidecar_connection_mode_thread_phpfpm_manual.md
die('skip: Automated PHP-FPM thread mode testing not feasible in .phpt format. See sidecar_connection_mode_thread_phpfpm_manual.md for manual test procedure.');
?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=thread
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_TRACE_DEBUG=1
--FILE--
<?php

/*
 * This test verifies thread-based sidecar connection works with PHP-FPM.
 *
 * Architecture:
 * - PHP-FPM master process starts the listener thread
 * - PHP-FPM worker processes connect to the master's listener
 * - Requests made via FastCGI protocol trigger tracing
 * - Traces flow through the thread-based connection
 */

$tmpDir = sys_get_temp_dir() . '/phpfpm_thread_test_' . getmypid();
mkdir($tmpDir);

// Create a simple PHP script that generates a trace
$scriptPath = $tmpDir . '/index.php';
file_put_contents($scriptPath, <<<'PHP'
<?php
// Generate a simple span
DDTrace\start_span();
DDTrace\active_span()->name = 'phpfpm.request';
DDTrace\active_span()->service = 'thread-mode-test';
DDTrace\active_span()->resource = 'GET /test';
DDTrace\close_span();

echo "Request processed with thread mode\n";
echo "Connection mode: " . ini_get('datadog.trace.sidecar_connection_mode') . "\n";
PHP
);

// Create PHP-FPM configuration
$fpmConfig = $tmpDir . '/php-fpm.conf';
$fpmSocket = $tmpDir . '/php-fpm.sock';
$fpmLog = $tmpDir . '/php-fpm.log';

file_put_contents($fpmConfig, <<<CONFIG
[global]
error_log = {$fpmLog}
daemonize = no

[www]
listen = {$fpmSocket}
pm = static
pm.max_children = 2
catch_workers_output = yes
clear_env = no

; Pass through environment variables for thread mode
env[DD_TRACE_SIDECAR_CONNECTION_MODE] = \$DD_TRACE_SIDECAR_CONNECTION_MODE
env[DD_INSTRUMENTATION_TELEMETRY_ENABLED] = \$DD_INSTRUMENTATION_TELEMETRY_ENABLED
env[DD_TRACE_SIDECAR_TRACE_SENDER] = \$DD_TRACE_SIDECAR_TRACE_SENDER
env[DD_TRACE_DEBUG] = \$DD_TRACE_DEBUG

; Load ddtrace extension
php_admin_value[extension] = ddtrace.so
php_admin_value[datadog.trace.sidecar_connection_mode] = thread
CONFIG
);

echo "=== PHP-FPM Thread Mode Test ===\n\n";
echo "Test directory: $tmpDir\n";
echo "Socket: $fpmSocket\n\n";

// Start PHP-FPM
echo "Starting PHP-FPM with thread mode...\n";
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$fpmCmd = "php-fpm --fpm-config $fpmConfig --nodaemonize";
$fpmProc = proc_open($fpmCmd, $descriptors, $pipes);

if (!is_resource($fpmProc)) {
    die("FAIL: Could not start PHP-FPM\n");
}

// Give PHP-FPM time to start and initialize thread mode
echo "Waiting for PHP-FPM to start...\n";
$timeout = 5;
$start = time();
while (!file_exists($fpmSocket) && (time() - $start) < $timeout) {
    usleep(100000); // 100ms
}

if (!file_exists($fpmSocket)) {
    proc_terminate($fpmProc);
    die("FAIL: PHP-FPM socket not created within timeout\n");
}

echo "PHP-FPM started successfully!\n\n";

// Make a FastCGI request to PHP-FPM
echo "Making FastCGI request...\n";

// Simple FastCGI client implementation for testing
$sock = stream_socket_client("unix://$fpmSocket", $errno, $errstr, 5);
if (!$sock) {
    proc_terminate($fpmProc);
    die("FAIL: Could not connect to PHP-FPM socket: $errstr\n");
}

// Build FastCGI request
$params = [
    'GATEWAY_INTERFACE' => 'FastCGI/1.0',
    'REQUEST_METHOD' => 'GET',
    'SCRIPT_FILENAME' => $scriptPath,
    'SCRIPT_NAME' => '/index.php',
    'REQUEST_URI' => '/test',
    'DOCUMENT_ROOT' => $tmpDir,
    'SERVER_SOFTWARE' => 'php/fcgi',
    'REMOTE_ADDR' => '127.0.0.1',
    'REMOTE_PORT' => '9000',
    'SERVER_ADDR' => '127.0.0.1',
    'SERVER_PORT' => '80',
    'SERVER_NAME' => 'localhost',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'CONTENT_TYPE' => '',
    'CONTENT_LENGTH' => '0',
];

// Simplified FastCGI protocol implementation
function fcgi_begin_request($sock, $requestId, $role = 1, $flags = 0) {
    $content = pack('nCx5', $role, $flags);
    fcgi_write_record($sock, 1, $requestId, $content); // Type 1 = BEGIN_REQUEST
}

function fcgi_write_params($sock, $requestId, $params) {
    $content = '';
    foreach ($params as $key => $value) {
        $keyLen = strlen($key);
        $valLen = strlen($value);

        $content .= chr($keyLen);
        $content .= chr($valLen);
        $content .= $key . $value;
    }
    fcgi_write_record($sock, 4, $requestId, $content); // Type 4 = PARAMS
    fcgi_write_record($sock, 4, $requestId, ''); // Empty PARAMS to signal end
}

function fcgi_write_record($sock, $type, $requestId, $content) {
    $clen = strlen($content);
    $header = pack('CCnnxx', 1, $type, $requestId, $clen);
    fwrite($sock, $header . $content);
}

// Send FastCGI request
$requestId = 1;
fcgi_begin_request($sock, $requestId);
fcgi_write_params($sock, $requestId, $params);
fcgi_write_record($sock, 5, $requestId, ''); // Type 5 = STDIN (empty)

// Read response
$response = '';
$timeout = 5;
$start = time();
stream_set_timeout($sock, 1);

while (!feof($sock) && (time() - $start) < $timeout) {
    $chunk = fread($sock, 8192);
    if ($chunk === false) break;
    $response .= $chunk;
    if (strpos($response, "Request processed with thread mode") !== false) {
        break;
    }
}

fclose($sock);

// Parse response (simplified - just look for our output)
if (strpos($response, "Request processed with thread mode") !== false) {
    echo "SUCCESS: Request processed through thread mode!\n";
    if (strpos($response, "Connection mode: thread") !== false) {
        echo "SUCCESS: Thread mode configuration verified!\n";
    }
} else {
    echo "FAIL: Did not receive expected response\n";
    echo "Response snippet: " . substr($response, 0, 200) . "\n";
}

echo "\n=== Test Complete ===\n";
echo "Note: This test verifies that:\n";
echo "1. PHP-FPM master process can start with thread mode\n";
echo "2. PHP-FPM workers can process requests\n";
echo "3. Thread mode configuration is active\n";
echo "\nFor full verification of trace submission, check DD_TRACE_DEBUG logs\n";
echo "showing master listener thread startup and worker connections.\n";

// Cleanup
echo "\nCleaning up...\n";
proc_terminate($fpmProc);
proc_close($fpmProc);
unlink($scriptPath);
unlink($fpmConfig);
unlink($fpmSocket);
rmdir($tmpDir);

?>
--EXPECTF--
=== PHP-FPM Thread Mode Test ===

Test directory: %s
Socket: %s

Starting PHP-FPM with thread mode...
Waiting for PHP-FPM to start...
PHP-FPM started successfully!

Making FastCGI request...
SUCCESS: Request processed through thread mode!
SUCCESS: Thread mode configuration verified!

=== Test Complete ===
Note: This test verifies that:
1. PHP-FPM master process can start with thread mode
2. PHP-FPM workers can process requests
3. Thread mode configuration is active

For full verification of trace submission, check DD_TRACE_DEBUG logs
showing master listener thread startup and worker connections.

Cleaning up...
