--TEST--
FFE evaluations retain resolved application identity across changes before flush
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
                $context = $payload['context'];
                $key = implode('|', array(
                    isset($context['service']) ? $context['service'] : '',
                    isset($context['env']) ? $context['env'] : '',
                    isset($context['version']) ? $context['version'] : '',
                ));
                foreach ($payload['flagEvaluations'] as $row) {
                    if (isset($contexts[$key])) throw new RuntimeException('duplicate application row');
                    $contexts[$key] = $row['flag']['key'] === 'same-flag'
                        && isset($row['context']['dd'])
                        && $row['context']['dd'] === array('service' => $context['service'])
                        && $row['evaluation_count'] === 1
                        && $row['first_evaluation'] === 1700000000123
                        && $row['last_evaluation'] === 1700000000123;
                }
            }
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
            fclose($connection);
        }
        $status = proc_get_status($process);
        if (!$status['running']) { $exitCode = $status['exitcode']; break; }
    }
    if ($exitCode !== 0) throw new RuntimeException('child failed: ' . ffeTestProcessRead($pipes[2]));
    foreach (array(
        'flag_evaluation_identity_app.php|none|',
        'app-a|test|v1',
        'app-b|test|v1',
        'app-b|production|v1',
        'app-b|production|v2',
    ) as $key) {
        echo $key, '='; var_dump(isset($contexts[$key]) && $contexts[$key]);
    }
    echo 'application_groups='; var_dump(count($contexts));
} finally {
    if ($exitCode === null) proc_terminate($process);
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($process);
    fclose($listener);
}
?>
--EXPECT--
flag_evaluation_identity_app.php|none|=bool(true)
app-a|test|v1=bool(true)
app-b|test|v1=bool(true)
app-b|production|v1=bool(true)
app-b|production|v2=bool(true)
application_groups=int(5)
