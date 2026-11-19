--TEST--
A stalled exposure endpoint preserves rejected observations for request-end batch delivery
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
DD_TRACE_AGENT_TIMEOUT=30000
DD_SERVICE=php-admission-overflow
--FILE--
<?php
require __DIR__ . '/stubs/ffe_process.inc';
$listener = stream_socket_server('tcp://127.0.0.1:0');
$env = ffeTestIsolatedSidecarEnvironment(array_merge(getenv(), array(
    'DD_TRACE_AGENT_URL' => 'http://' . stream_socket_get_name($listener, false),
)));
$child = ffeTestProcess(__DIR__ . '/stubs/exposure_admission_overflow_app.php', $pipes, $env);
$held = $seen = array();
$out = $err = '';
$released = false;
$reply = function ($socket) {
    fwrite($socket, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
    fclose($socket);
};
try {
    $deadline = microtime(true) + 20;
    while (microtime(true) < $deadline && count($seen) < 1400) {
        $out .= ffeTestProcessRead($pipes[1]);
        $err .= ffeTestProcessRead($pipes[2]);
        if (!$released && strpos($out, "\n") !== false) {
            if (trim($out) !== 'overloaded') throw new RuntimeException($out . $err);
            $released = true;
            foreach ($held as $socket) $reply($socket);
            $held = array();
            fwrite($pipes[0], "finish\n");
            fflush($pipes[0]);
        }
        $socket = @stream_socket_accept($listener, 0.02);
        if (!$socket) continue;
        $request = ffeTestReadRequest($socket);
        if ($request && strpos($request[0], '/api/v2/exposures ') !== false) {
            foreach (json_decode($request[2], true)['exposures'] as $row) {
                $id = (int) $row['subject']['id'];
                if ($id < 0 || $id >= 1400 || isset($seen[$id])) throw new RuntimeException("unexpected subject $id");
                if ($row['subject']['attributes']['index'] !== $id || $row['serial_id'] !== 0 || $row['features']['arm'] !== 'control') {
                    throw new RuntimeException("changed exposure fields for subject $id");
                }
                $seen[$id] = true;
            }
            if (!$released) { $held[] = $socket; continue; }
        }
        $reply($socket);
    }
    echo trim($out), "\nunique exposures: ", count($seen), "\n";
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
unique exposures: 1400
