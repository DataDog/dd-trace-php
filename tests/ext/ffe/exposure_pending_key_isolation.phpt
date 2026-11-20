--TEST--
A refused exposure fences only its delivery key until the sidecar confirms the older assignment
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70200 || !function_exists('proc_open') || !function_exists('stream_socket_server') ||
    !getenv('TEST_PHP_EXECUTABLE')) die('skip: child PHP and stream sockets required');
?>
--ENV--
DD_TRACE_CLI_ENABLED=1
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=true
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_TRACE_AGENT_TIMEOUT=150000
DD_SERVICE=php-pending-key
--FILE--
<?php
require __DIR__ . '/stubs/ffe_process.inc';
$listener = stream_socket_server('tcp://127.0.0.1:0');
$env = ffeTestIsolatedSidecarEnvironment(array_merge(getenv(), array(
    'DD_TRACE_AGENT_URL' => 'http://' . stream_socket_get_name($listener, false),
    '_DD_DEBUG_SIDECAR_IPC_MODE' => 'instance_per_process',
    '_DD_DEBUG_SIDECAR_IDLE_LINGER_TIME_SECS' => '45',
)));
$child = ffeTestProcess(__DIR__ . '/stubs/exposure_pending_key_app.php', $pipes, $env);
$subject = null;
$acknowledged = 0;
$seen = $held = array();
$out = $err = '';
$ready = $decided = false;
$reply = function ($socket) {
    fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
    fclose($socket);
};
try {
    $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 120 : 45);
    while (microtime(true) < $deadline) {
        $out .= ffeTestProcessRead($pipes[1]);
        $err .= ffeTestProcessRead($pipes[2]);
        if ($subject === null && preg_match('/^blocked:(load-[0-9]+)\n/', $out, $m)) {
            $subject = $m[1];
            foreach ($held as list($socket, $count)) { $reply($socket); $acknowledged += $count; }
            $held = array();
        }
        if (strpos($out, "decided\n") !== false) $decided = true;
        if ($decided && isset($seen[$subject]) && in_array('C', $seen[$subject], true)) break;
        if (!proc_get_status($child)['running']) throw new RuntimeException($out . $err . ffeTestProcessRead($pipes[2]));
        $socket = @stream_socket_accept($listener, 0.02);
        if (!$socket) continue;
        $request = ffeTestReadRequest($socket);
        if (!$request || strpos($request[0], '/api/v2/exposures ') === false) { $reply($socket); continue; }
        $body = json_decode($request[2], true);
        foreach ($body['exposures'] as $row) {
            if ($body['context']['service'] === 'php-pending-key' && $row['flag']['key'] === 'flag') {
                $seen[$row['subject']['id']][] = $row['variant']['key'];
            }
        }
        $count = count($body['exposures']);
        if ($subject === null) { $held[] = array($socket, $count); continue; }
        // A later POST on the route proves the writer released credits for its preceding POSTs.
        if (!$ready && $acknowledged >= 8) {
            fwrite($pipes[0], "capacity\n"); fflush($pipes[0]); $ready = true;
        }
        $reply($socket);
        $acknowledged += $count;
    }
    if (!$decided || ($seen[$subject] ?? array()) !== array('A','B','C') || ($seen['disjoint'] ?? array()) !== array('A')) {
        throw new RuntimeException('incorrect delivery order: ' . json_encode($seen[$subject] ?? array()) . ' ' . $out . $err);
    }
    fwrite($pipes[0], "delivered\n"); fflush($pipes[0]);
    echo "disjoint admitted and repeated; pending subject delivered A, B, C\n";
} finally {
    foreach ($held as list($socket, $count)) fclose($socket);
    foreach ($pipes as $pipe) fclose($pipe);
    ffeTestProcessTerminate($child);
    proc_close($child);
    fclose($listener);
}
?>
--EXPECT--
disjoint admitted and repeated; pending subject delivered A, B, C
