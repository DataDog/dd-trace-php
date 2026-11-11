--TEST--
FFE evaluation payload identity is attributed by the sidecar and follows runtime service/env/version changes
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: subprocess sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
?>
--ENV--
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_CLI_ENABLED=1
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true
DD_TRACE_AGENT_TIMEOUT=5000
DD_SERVICE=
DD_ENV=
DD_VERSION=
--FILE--
<?php
require __DIR__ . '/stubs/ffe_process.inc';
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) throw new RuntimeException($error);
putenv('DD_TRACE_AGENT_URL=http://' . stream_socket_get_name($listener, false));
$process = ffeTestProcess(__DIR__ . '/stubs/flag_evaluation_identity_app.php', $pipes);
$contexts = array();
$exitCode = null;
try {
    $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 60 : 15);
    while (microtime(true) < $deadline) {
        $connection = @stream_socket_accept($listener, 0.1);
        if ($connection) {
            stream_set_timeout($connection, 2);
            $request = fgets($connection);
            $length = 0;
            while (($line = fgets($connection)) !== false && trim($line) !== '') {
                if (stripos($line, 'Content-Length:') === 0) $length = (int) substr($line, 15);
            }
            $body = '';
            while (strlen($body) < $length && !feof($connection)) {
                $chunk = fread($connection, $length - strlen($body));
                if ($chunk === false || $chunk === '') break;
                $body .= $chunk;
            }
            if (strpos($request, '/flagevaluation ') !== false) {
                $payload = json_decode($body, true);
                // The extension submits an empty context; the sidecar fills
                // it from the application set on the transport.
                foreach ($payload['flagEvaluations'] as $row) {
                    $context = $payload['context'];
                    ksort($context);
                    $contexts[$row['flag']['key']] = json_encode(array(
                        'context' => $context,
                        'dd' => isset($row['context']['dd']) ? $row['context']['dd'] : null,
                        'count' => $row['evaluation_count'],
                    ));
                }
            }
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
            fclose($connection);
        }
        $status = proc_get_status($process);
        if (!$status['running']) { $exitCode = $status['exitcode']; break; }
    }
    if ($exitCode !== 0) throw new RuntimeException('child failed: ' . ffeTestProcessRead($pipes[2]));
    foreach (array('default', 'configured') as $flag) {
        echo $flag, '=', $contexts[$flag], "\n";
    }
} finally {
    if ($exitCode === null) proc_terminate($process);
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($process);
    fclose($listener);
}
?>
--EXPECT--
default={"context":{"env":"none","service":"flag_evaluation_identity_app.php"},"dd":{"service":"flag_evaluation_identity_app.php"},"count":1}
configured={"context":{"env":"changed-env","service":"changed-service","version":"changed-version"},"dd":{"service":"changed-service"},"count":1}
