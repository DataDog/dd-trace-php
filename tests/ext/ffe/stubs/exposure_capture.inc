<?php
require_once __DIR__ . '/ffe_process.inc';

function ffeCaptureExposures($app, $barriers, $expectedCount) {
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$listener) throw new RuntimeException($error);
    $environment = ffeTestIsolatedSidecarEnvironment(array_merge(getenv(), array(
        'DD_TRACE_AGENT_URL' => 'http://' . stream_socket_get_name($listener, false),
    )));
    $process = ffeTestProcess($app, $pipes, $environment);
    $rows = array();
    $stdout = $stderr = '';
    $exitCode = null;
    $exitedAt = null;
    $lastCaptureAt = 0;
    try {
        // Valgrind instruments the PHP producer and its sidecar before the first POST reaches the listener.
        $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 240 : 20);
        do {
            $stdout .= ffeTestProcessRead($pipes[1]);
            $stderr .= ffeTestProcessRead($pipes[2]);
            // Release the next exposure only once the sidecar sent this one.
            if ($barriers && count($rows) >= key($barriers) && strpos($stdout, current($barriers)) !== false) {
                unset($barriers[key($barriers)]);
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
            if ($exitedAt !== null && count($rows) >= $expectedCount && microtime(true) - max($exitedAt, $lastCaptureAt) > 0.5) break;
            $connection = @stream_socket_accept($listener, 0.1);
            if (!$connection) continue;
            if ($read = ffeTestReadRequest($connection)) {
                list($request, , $body) = $read;
                if (strpos($request, '/api/v2/exposures ') !== false) {
                    $payload = json_decode($body, true);
                    foreach ($payload['exposures'] as $exposure) {
                        $rows[] = $exposure;
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
            . ' exposures=' . json_encode(array_column(array_column($rows, 'allocation'), 'key')));
        echo $stdout;
        return $rows;
    } finally {
        foreach ($pipes as $pipe) fclose($pipe);
        if ($exitCode === null) ffeTestProcessTerminate($process);
        proc_close($process);
        fclose($listener);
    }
}
