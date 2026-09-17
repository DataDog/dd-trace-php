--TEST--
Linux OTel Process Context is published during MINIT
--SKIPIF--
<?php
if (PHP_OS !== 'Linux') die('skip: Linux only');
if (PHP_VERSION_ID < 70200) die('skip: TEST_PHP_EXTRA_ARGS requires PHP 7.2+');
if (!function_exists('proc_open')) die('skip: proc_open required');
if (!is_readable('/proc/self/maps')) die('skip: readable /proc maps required');
if (!getenv('TEST_PHP_EXECUTABLE')) die('skip: TEST_PHP_EXECUTABLE required');
if (getenv('PHP_PEAR_RUNTESTS') === '1') {
    die('skip: pecl run-tests does not support TEST_PHP_EXECUTABLE');
}
?>
--ENV--
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

$php = getenv('TEST_PHP_EXECUTABLE');
$args = trim(getenv('TEST_PHP_ARGS') . ' ' . getenv('TEST_PHP_EXTRA_ARGS'));
$command = 'exec ' . escapeshellarg($php);
if ($args !== '') {
    $command .= ' ' . $args;
}
$command .= ' -S 127.0.0.1:0 -t ' . escapeshellarg(__DIR__);

$process = proc_open(
    $command,
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes
);
if (!is_resource($process)) {
    throw new RuntimeException('failed to start the PHP CLI server');
}

fclose($pipes[0]);
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

try {
    $status = proc_get_status($process);
    $pid = $status['pid'];
    $stderr = '';
    $started = false;
    $deadline = microtime(true) + 5;

    // The CLI server announces startup after MINIT, then waits for its first RINIT.
    do {
        $stderr .= stream_get_contents($pipes[2]);
        if (strpos($stderr, 'Development Server') !== false
            && strpos($stderr, 'started') !== false
        ) {
            $started = true;
            break;
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);

    if (!$started) {
        echo 'Server failed to start: ', trim($stderr), PHP_EOL;
    } else {
        $maps = @file_get_contents("/proc/$pid/maps");
        echo 'One Process Context mapping before any request: ';
        var_dump($maps !== false && substr_count($maps, 'OTEL_CTX') === 1);
    }
} finally {
    proc_terminate($process);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
}

?>
--EXPECT--
One Process Context mapping before any request: bool(true)
