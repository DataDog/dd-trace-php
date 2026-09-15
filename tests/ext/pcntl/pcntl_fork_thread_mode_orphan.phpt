--TEST--
Thread mode sidecar: orphaned child process promotes itself to master after parent exits
--SKIPIF--
<?php if (!extension_loaded('pcntl')) die('skip: pcntl extension required'); ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: thread mode not supported on Windows'); ?>
<?php if (getenv('USE_ZEND_ALLOC') === '0' && !getenv('SKIP_ASAN')) die('skip: valgrind incompatible with thread mode sidecar'); ?>
<?php
// macOS's kqueue fds don't survive fork() (the child gets a copy of the fd number but the
// underlying kernel kqueue object is not inherited/valid), unlike Linux epoll fds. Tokio's I/O
// driver keeps a self-pipe/kqueue registration alive across the whole process lifetime, so any
// fork() while a tokio runtime is running -- exactly what this test does under thread mode --
// makes the child's I/O driver wake mechanism reference a dead kqueue fd. The next attempt to
// wake it (`ddog_sidecar_clear_inherited_listener`'s fork cleanup) panics with "Bad file
// descriptor" and aborts (Rust panics can't unwind across the FFI boundary), rather than
// producing the wrong output the way run-tests.php expects for a real bug -- this is a macOS
// kernel-level constraint on kqueue-across-fork, not a bug in our IPC layer.
if (PHP_OS === "Darwin") die("skip: kqueue fds don't survive fork() on macOS -- tokio's I/O driver can't recover after pcntl_fork() in thread mode there");
?>
--ENV--
DD_TRACE_SIDECAR_CONNECTION_MODE=thread
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
LSAN_OPTIONS=detect_leaks=0
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

// Creating and flushing a span triggers datadog_sidecar_ensure_active()
$span = DDTrace\start_span();
$span->name = 'orphaned-child-span';
DDTrace\close_span();

echo "Child span submitted\n";
exit(0);

?>
--EXPECT--
Child span submitted
