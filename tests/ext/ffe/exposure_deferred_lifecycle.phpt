--TEST--
Deferred exposures retain payload and scope through a logging toggle and request shutdown
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200 || !function_exists('proc_open') || !function_exists('stream_socket_server') || !getenv('TEST_PHP_EXECUTABLE')) die('skip: child PHP and stream sockets required');
?>
--ENV--
DD_TRACE_CLI_ENABLED=1
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_SERVICE=deferred-before
--FILE--
<?php
require __DIR__ . '/stubs/ffe_process.inc';
$listener = stream_socket_server('tcp://127.0.0.1:0');
$env = ffeTestIsolatedSidecarEnvironment(array_merge(getenv(), array(
    'DD_TRACE_AGENT_URL' => 'http://' . stream_socket_get_name($listener, false),
    '_DD_DEBUG_SIDECAR_IPC_MODE' => 'instance_per_process',
    '_DD_DEBUG_SIDECAR_IDLE_LINGER_TIME_SECS' => '45',
)));
$child = ffeTestProcess(__DIR__ . '/stubs/exposure_deferred_lifecycle_app.php', $pipes, $env);
$seen = array();
try {
    $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 120 : 30);
    while (!isset($seen['shutdown']) && microtime(true) < $deadline) {
        $socket = @stream_socket_accept($listener, 0.1);
        if (!$socket) {
            $state = proc_get_status($child);
            if (!$state['running'] && $state['exitcode'] > 0) {
                throw new RuntimeException(ffeTestProcessRead($pipes[1]) . ffeTestProcessRead($pipes[2]));
            }
            continue;
        }
        $request = ffeTestReadRequest($socket);
        if ($request && strpos($request[0], '/api/v2/exposures ') !== false) {
            $payload = json_decode($request[2], true);
            foreach ($payload['exposures'] as $row) {
                $id = $row['subject']['id'];
                if (!in_array($id, array('before', 'shutdown'), true) || isset($seen[$id]) ||
                    $row['subject']['attributes']['blob'] !== str_repeat('x', 70 * 1024) || $row['serial_id'] !== 0) {
                    throw new RuntimeException('unexpected row ' . $id);
                }
                $seen[$id] = $payload['context']['service'];
            }
        }
        fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
        fclose($socket);
    }
    if ($seen !== array('before' => 'deferred-before', 'shutdown' => 'deferred-after')) {
        throw new RuntimeException(json_encode($seen) . ' ' . ffeTestProcessRead($pipes[1]) . ffeTestProcessRead($pipes[2]));
    }
    echo "payload and original scope preserved through shutdown\n";
} finally {
    foreach ($pipes as $pipe) fclose($pipe);
    ffeTestProcessTerminate($child);
    proc_close($child);
    fclose($listener);
}
?>
--EXPECT--
payload and original scope preserved through shutdown
