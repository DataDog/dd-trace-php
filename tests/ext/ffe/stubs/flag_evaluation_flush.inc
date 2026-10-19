<?php
require __DIR__ . '/ffe_process.inc';
foreach (array('false', 'true') as $sidecarTraceSender) {
    foreach ($modes as $mode) {
        putenv('DD_TRACE_SIDECAR_TRACE_SENDER=' . $sidecarTraceSender);
        putenv('DD_TRACE_FORCE_FLUSH_ON_SHUTDOWN=' . ($mode === 'shutdown' ? 'true' : 'false'));
        putenv('PHP_FFE_FLUSH_MODE=' . $mode);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$listener) throw new RuntimeException($error);
        putenv('DD_TRACE_AGENT_URL=http://' . stream_socket_get_name($listener, false));
        $process = ffeTestProcess($flushApp ?? __DIR__ . '/flag_evaluation_flush_app.php', $pipes);
        $stdout = $stderr = '';
        $exitCode = null;
        $exitedAt = null;
        $lastCaptureAt = 0;
        $blocked = $released = false;
        $counts = array();
        try {
            // Valgrind also instruments the child, including startup and
            // shutdown. Keep the HTTP barrier below unchanged; only allow
            // more time for the instrumented process to finish.
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
                // The sidecar can outlive PHP. Keep capturing until it is quiet
                // so a late duplicate cannot hide behind the parent's exit.
                if ($exitedAt !== null && microtime(true) - max($exitedAt, $lastCaptureAt) > 0.5) break;
                $connection = @stream_socket_accept($listener, 0.1);
                if (!$connection) continue;
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
                    $lastCaptureAt = microtime(true);
                    $payload = json_decode($body, true);
                    foreach ($payload['flagEvaluations'] as $row) {
                        $key = $row['flag']['key'];
                        $counts[$key] = (isset($counts[$key]) ? $counts[$key] : 0) + $row['evaluation_count'];
                    }
                }
                if (strpos($request, '/flagevaluation ') !== false && !$released) {
                    $released = true;
                    fwrite($pipes[0], "flush\n");
                    fflush($pipes[0]);
                    // Hold the HTTP response while the child tries to flush.
                    // A missing FFE flush returns/exits during this interval.
                    $blocked = true;
                    $holdUntil = microtime(true) + 0.3;
                    do {
                        $stdout .= ffeTestProcessRead($pipes[1]);
                        $status = proc_get_status($process);
                        if (strpos($stdout, 'returned') !== false || !$status['running']) {
                            $blocked = false;
                            if (!$status['running']) {
                                $exitCode = $status['exitcode'];
                                $exitedAt = microtime(true);
                            }
                            break;
                        }
                        usleep(1000);
                    } while (microtime(true) < $holdUntil);
                }
                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
                fclose($connection);
            } while (microtime(true) < $deadline);
            $stdout .= ffeTestProcessRead($pipes[1]);
            $stderr .= ffeTestProcessRead($pipes[2]);
            if ($exitCode !== 0) throw new RuntimeException('child failed or timed out: ' . $stdout . $stderr);
            $expected = $mode === 'fork' ? array('flush-child' => 1, 'flush-flag' => 1) : array('flush-flag' => 1);
            ksort($counts);
            echo $sidecarTraceSender, '/', $mode, ': ';
            $terminates = in_array($mode, array('shutdown', 'sigterm', 'sigint'), true);
            var_dump($counts === $expected && $blocked && ($terminates || strpos($stdout, 'returned') !== false));
        } finally {
            if ($exitCode === null) proc_terminate($process);
            foreach ($pipes as $pipe) fclose($pipe);
            proc_close($process);
            fclose($listener);
        }
    }
}
?>
