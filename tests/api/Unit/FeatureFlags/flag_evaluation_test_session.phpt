--TEST--
FFE writers follow a changed test-session token without rerouting earlier evaluations
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and stream sockets required');
?>
--ENV--
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_CLI_ENABLED=1
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true
DD_SERVICE=php-ffe-test-session
--FILE--
<?php
require __DIR__ . '/../../../ext/ffe/stubs/ffe_process.inc';

$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) throw new RuntimeException($error);
putenv('DD_TRACE_AGENT_URL=http://' . stream_socket_get_name($listener, false));
putenv('DD_TRACE_AGENT_TEST_SESSION_TOKEN=session-a');
$process = ffeTestProcess(__DIR__ . '/../../../Common/FeatureFlags/flag_evaluation_test_session_app.php', $pipes);
fclose($pipes[0]);
$stdout = $stderr = '';
$exitCode = $exitedAt = null;
$counts = array();
try {
    // Include instrumented child startup/shutdown, not just the HTTP flush.
    $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 60 : 15);
    do {
        $stdout .= ffeTestProcessRead($pipes[1]);
        $stderr .= ffeTestProcessRead($pipes[2]);
        if ($exitedAt === null) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                $exitedAt = microtime(true);
            }
        }
        if ($exitedAt !== null && microtime(true) - $exitedAt > 0.5) break;
        $connection = @stream_socket_accept($listener, 0.1);
        if (!$connection) continue;
        stream_set_timeout($connection, 2);
        $request = fgets($connection);
        $headers = array();
        while (($line = fgets($connection)) !== false && trim($line) !== '') {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        $length = (int) ($headers['content-length'] ?? 0);
        $body = '';
        while (strlen($body) < $length && !feof($connection)) {
            $chunk = fread($connection, $length - strlen($body));
            if ($chunk === false || $chunk === '') break;
            $body .= $chunk;
        }
        if (strpos($request, '/evp_proxy/') !== false) {
            $payload = json_decode($body, true);
            $token = $headers['x-datadog-test-session-token'] ?? 'missing';
            if (!isset($counts[$token])) $counts[$token] = array('evaluations' => 0, 'exposures' => 0);
            if (strpos($request, '/flagevaluation ') !== false) {
                foreach ($payload['flagEvaluations'] as $row) {
                    $counts[$token]['evaluations'] += $row['evaluation_count'];
                }
            } else {
                $counts[$token]['exposures'] += count($payload['exposures']);
            }
        }
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
        fclose($connection);
    } while (microtime(true) < $deadline);
    $stdout .= ffeTestProcessRead($pipes[1]);
    $stderr .= ffeTestProcessRead($pipes[2]);
    if ($exitCode !== 0) throw new RuntimeException('child failed or timed out: ' . $stdout . $stderr);
    ksort($counts);
    echo json_encode($counts), "\n";
} finally {
    if ($exitCode === null) proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    fclose($listener);
}
?>
--EXPECT--
{"session-a":{"evaluations":1,"exposures":1},"session-b":{"evaluations":1,"exposures":1}}
