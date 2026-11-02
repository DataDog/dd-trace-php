--TEST--
Cached service/env names do not outlive a request whose startup failed
--DESCRIPTION--
The service and env names last sent to the sidecar are cached across
requests. With no DD_SERVICE/DD_ENV they are request-heap strings. When a
RINIT running after the one setting them bails out, PHP skips every RSHUTDOWN
and resets the heap; the next request served by the same process must not
release the stale strings. The first request fails its startup on purpose:
its many headers exhaust the memory limit while the tracer's RINIT
materializes $_SERVER.
--SKIPIF--
<?php
if (PHP_OS !== 'Linux') die('skip: Linux only');
if (PHP_VERSION_ID < 80100) die('skip: the CLI server ends failed request startups only on PHP 8.1+');
// mbstring's RINIT frees its previous detect order list since PHP 8.6, which dangles when RSHUTDOWN was skipped
if (PHP_VERSION_ID >= 80600 && extension_loaded('mbstring')) die('skip: mbstring corrupts the heap after a failed request startup on PHP 8.6+');
if (!function_exists('proc_open')) die('skip: proc_open required');
if (!function_exists('stream_socket_server')) die('skip: stream sockets required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
if (getenv('PHP_PEAR_RUNTESTS') === '1') {
    die('skip: pecl run-tests does not support TEST_PHP_EXECUTABLE');
}
?>
--ENV--
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_REMOTE_CONFIG_ENABLED=0
DD_TRACE_GIT_METADATA_ENABLED=0
--FILE--
<?php

$php = getenv('TEST_PHP_EXECUTABLE');
$args = trim((string) getenv('TEST_PHP_EXTRA_ARGS'));

$listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!is_resource($listener)) {
    throw new RuntimeException("failed to reserve a port: $errorMessage ($errorCode)");
}
$address = stream_socket_get_name($listener, false);
fclose($listener);
if ($address === false || !preg_match('/:(\d+)$/', $address, $matches)) {
    throw new RuntimeException('failed to determine the reserved port');
}
$port = $matches[1];

$router = tempnam(sys_get_temp_dir(), 'dd_failed_startup_router');
file_put_contents($router, '<?php echo "ok\n";');

// $_SERVER must be built lazily (register_argc_argv=0), inside the tracer's
// RINIT. A single 2MB chunk fits a normal request, not one with ~13k headers.
// The memory limit is only chunk-granular with the Zend allocator.
$command = 'exec env USE_ZEND_ALLOC=1 ' . escapeshellarg($php);
if ($args !== '') {
    $command .= ' ' . $args;
}
$command .= ' -d register_argc_argv=0 -d memory_limit=2M'
    . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($router);

$process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('failed to start the PHP CLI server');
}
fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

function request($port, $headers) {
    $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 5);
    if (!is_resource($connection)) {
        return "connection failed: $errorMessage";
    }
    stream_set_timeout($connection, 30);
    fwrite($connection, "GET / HTTP/1.0\r\nHost: localhost\r\n$headers\r\n");
    $response = stream_get_contents($connection);
    fclose($connection);
    return $response;
}

function describe($response) {
    if (strpos($response, 'Allowed memory size') !== false) {
        return 'startup failed (memory limit)';
    }
    $parts = explode("\r\n\r\n", $response, 2);
    return isset($parts[1]) ? trim($parts[1]) : 'no response: ' . var_export($response, true);
}

try {
    $deadline = microtime(true) + (getenv('USE_ZEND_ALLOC') === '0' ? 30 : 10);
    $stderr = '';
    do {
        $stderr .= stream_get_contents($pipes[2]) . stream_get_contents($pipes[1]);
        $probe = @stream_socket_client('tcp://127.0.0.1:' . $port, $errorCode, $errorMessage, 0.1);
        if (is_resource($probe)) {
            fclose($probe);
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    // Header names must be distinct to become separate $_SERVER entries; the
    // CLI server rejects requests with more than 80KB of headers.
    $manyHeaders = '';
    for ($i = 0; $i < 13000; $i++) {
        $manyHeaders .= base_convert((string) $i, 10, 36) . ":\r\n";
    }

    $failed = false;
    for ($round = 1; $round <= 2; $round++) {
        echo "Round $round, failing request: ", describe(request($port, $manyHeaders)), PHP_EOL;
        $result = describe(request($port, ''));
        $failed = $failed || $result !== 'ok';
        echo "Round $round, normal request: ", $result, PHP_EOL;
    }

    $status = proc_get_status($process);
    echo 'Server running: ', var_export($status['running'] && !$failed, true), PHP_EOL;
    if (!$status['running'] || $failed) {
        usleep(100000);
        $stderr .= stream_get_contents($pipes[2]) . stream_get_contents($pipes[1]);
        echo $stderr;
    }
} finally {
    proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    unlink($router);
}

?>
--EXPECT--
Round 1, failing request: startup failed (memory limit)
Round 1, normal request: ok
Round 2, failing request: startup failed (memory limit)
Round 2, normal request: ok
Server running: true
