--TEST--
A stalled exposure endpoint preserves rejected observations for batch delivery
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
DD_SERVICE=php-admission-overflow
--FILE--
<?php
require __DIR__ . '/stubs/ffe_process.inc';
$listener = stream_socket_server('tcp://127.0.0.1:0');
$env = ffeTestIsolatedSidecarEnvironment(array_merge(getenv(), array(
    'DD_TRACE_AGENT_URL' => 'http://' . stream_socket_get_name($listener, false),
    '_DD_DEBUG_SIDECAR_IPC_MODE' => 'instance_per_process',
)));
$child = ffeTestProcess(__DIR__ . '/stubs/exposure_admission_overflow_app.php', $pipes, $env);
$held = $seen = $required = $allowed = array();
$out = $err = '';
$released = $flushed = false;
$reply = function ($socket) {
    fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
    fclose($socket);
};
try {
    $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 120 : 45);
    while (microtime(true) < $deadline && (!$flushed || array_diff_key($required, $seen))) {
        $out .= ffeTestProcessRead($pipes[1]);
        $err .= ffeTestProcessRead($pipes[2]);
        if (!$released && strpos($out, "\n") !== false) {
            $manifest = json_decode(trim($out), true);
            if (!is_array($manifest) || count($manifest[0]) !== 1400) throw new RuntimeException($out . $err);
            $required = array_fill_keys($manifest[0], true);
            $allowed = $required + array_fill_keys($manifest[1], true);
            $out = '';
            $released = true;
            foreach ($held as $socket) $reply($socket);
            $held = array();
            fwrite($pipes[0], "finish\n");
            fflush($pipes[0]);
        } elseif ($released && ($end = strpos($out, "\n")) !== false) {
            $phase = substr($out, 0, $end);
            $out = substr($out, $end + 1);
            if (preg_match('/^pending:[1-9][0-9]*$/D', $phase)) {
                fwrite($pipes[0], "continue\n");
                fflush($pipes[0]);
            } elseif ($phase === 'flushed') {
                $flushed = true;
            } else {
                throw new RuntimeException("unexpected handoff phase $phase: " . $out . $err);
            }
        }
        $state = proc_get_status($child);
        if (!$state['running']) throw new RuntimeException('child exited before sink acknowledgement: ' . $err . ffeTestProcessRead($pipes[1]) . ffeTestProcessRead($pipes[2]));
        $socket = @stream_socket_accept($listener, 0.02);
        if (!$socket) continue;
        $request = ffeTestReadRequest($socket);
        if ($request && strpos($request[0], '/api/v2/exposures ') !== false) {
            foreach (json_decode($request[2], true)['exposures'] as $row) {
                $id = (int) $row['subject']['id'];
                if ($id < 0 || isset($seen[$id])) throw new RuntimeException("unexpected subject $id");
                if ($row['subject']['attributes']['index'] !== $id || $row['serial_id'] !== 0 || $row['features']['arm'] !== 'control') {
                    throw new RuntimeException("changed exposure fields for subject $id");
                }
                $seen[$id] = true;
            }
            if (!$released) { $held[] = $socket; continue; }
        }
        $reply($socket);
    }
    if (!$flushed || array_diff_key($required, $seen) || array_diff_key($seen, $allowed)) {
        throw new RuntimeException('incomplete or unexpected exposure delivery: ' . count($seen) . ' received; ' . $err);
    }
    fwrite($pipes[0], "delivered\n");
    fflush($pipes[0]);
    echo "overloaded\nunique confirmed exposures: ", count($required), "\n";
} finally {
    foreach ($held as $socket) fclose($socket);
    foreach ($pipes as $pipe) fclose($pipe);
    ffeTestProcessTerminate($child);
    proc_close($child);
    fclose($listener);
}
?>
--EXPECT--
overloaded
unique confirmed exposures: 1400
