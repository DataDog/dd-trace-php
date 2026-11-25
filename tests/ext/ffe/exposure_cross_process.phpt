--TEST--
FFE exposures that repeat in one process reach the sidecar, which deduplicates across processes
--SKIPIF--
<?php
if (!extension_loaded('pcntl')) die('skip: pcntl extension required');
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) die('skip: process and stream sockets required');
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
DD_TRACE_SIDECAR_CONNECTION_MODE=subprocess
DD_SERVICE=php-ffe-exposure-cross-process
--FILE--
<?php
require __DIR__ . '/stubs/ffe_process.inc';
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) throw new RuntimeException($error);
$environment = ffeTestIsolatedSidecarEnvironment(array_merge(getenv(), array(
    'DD_TRACE_AGENT_URL' => 'http://' . stream_socket_get_name($listener, false),
)));
$process = ffeTestProcess(__DIR__ . '/stubs/exposure_cross_process_app.php', $pipes, $environment);
$allocations = array();
$stdout = $stderr = '';
$exitCode = null;
$exitedAt = null;
$lastCaptureAt = 0;
$barriers = array('first:', 'other_worker:');
try {
    $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 60 : 20);
    do {
        $stdout .= ffeTestProcessRead($pipes[1]);
        $stderr .= ffeTestProcessRead($pipes[2]);
        // Release the next exposure only once the sidecar sent this one.
        $released = 2 - count($barriers);
        if ($barriers && strpos($stdout, $barriers[0]) !== false && count($allocations) > $released) {
            array_shift($barriers);
            fwrite($pipes[0], "next\n");
            fflush($pipes[0]);
        }
        if ($exitedAt === null) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                $exitedAt = microtime(true);
            }
        }
        // The sidecar can outlive PHP. Keep capturing until it is quiet.
        if ($exitedAt !== null && count($allocations) >= 3 && microtime(true) - max($exitedAt, $lastCaptureAt) > 0.5) break;
        $connection = @stream_socket_accept($listener, 0.1);
        if (!$connection) continue;
        if ($read = ffeTestReadRequest($connection)) {
            list($request, , $body) = $read;
            if (strpos($request, '/api/v2/exposures ') !== false) {
                $payload = json_decode($body, true);
                foreach ($payload['exposures'] as $exposure) {
                    if ($exposure['flag']['key'] !== 'flag' || $exposure['subject']['id'] !== 'user') {
                        throw new RuntimeException('unexpected exposure: ' . $body);
                    }
                    $allocations[] = $exposure['allocation']['key'];
                }
                $lastCaptureAt = microtime(true);
            }
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
        }
        fclose($connection);
    } while (microtime(true) < $deadline);
    $stdout .= ffeTestProcessRead($pipes[1]);
    $stderr .= ffeTestProcessRead($pipes[2]);
    if ($exitCode !== 0) throw new RuntimeException('child failed or timed out (exit=' . var_export($exitCode, true) . '): ' . $stdout . $stderr
        . ' exposures=' . json_encode($allocations));
    echo $stdout;
    echo 'exposures=', json_encode($allocations), "\n";
} finally {
    foreach ($pipes as $pipe) fclose($pipe);
    if ($exitCode === null) ffeTestProcessTerminate($process);
    proc_close($process);
    fclose($listener);
}
?>
--EXPECT--
first: handed_off=true
other_worker: handed_off=true
back: handed_off=true
repeat: handed_off=true
exposures=["allocation-a","allocation-b","allocation-a"]
