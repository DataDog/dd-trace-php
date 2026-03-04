--TEST--
Thread mode sidecar: orphaned child process promotes itself to master after parent exits
--SKIPIF--
<?php if (!extension_loaded('pcntl')) die('skip: pcntl extension required'); ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: thread mode not supported on Windows'); ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) die('skip: valgrind incompatible with thread mode sidecar'); ?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=thread
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();

$pid = pcntl_fork();

if ($pid < 0) {
    echo "Fork failed\n";
    exit(1);
}

if ($pid > 0) {
    exit(0);
}

usleep(500000); // 500ms

// Creating and flushing a span triggers ddtrace_sidecar_ensure_active(), which
$span = DDTrace\start_span();
$span->name = 'orphaned-child-span';
DDTrace\close_span();

echo "Child span submitted\n";
exit(0);

?>
--EXPECT--
Child span submitted
