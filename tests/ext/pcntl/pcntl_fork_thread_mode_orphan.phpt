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

// The parent initializes the sidecar master listener in thread mode, then forks
// and exits immediately. When the parent's PHP MSHUTDOWN runs, it shuts down
// the master listener thread and removes the socket. The child (orphan) must
// detect that the inherited transport is broken, promote itself to master, and
// still be able to submit traces.

// Trigger sidecar setup in the parent so the master listener thread is running.
DDTrace\start_span();
DDTrace\close_span();

$pid = pcntl_fork();

if ($pid < 0) {
    echo "Fork failed\n";
    exit(1);
}

if ($pid > 0) {
    // Parent exits immediately. Its MSHUTDOWN will shut down the master
    // listener thread and clean up the socket, breaking the child's transport.
    exit(0);
}

// Child process:
//   - ddtrace_sidecar_master_pid is still the parent's PID (inherited)
//   - The inherited transport will be broken once parent's thread exits
// Wait long enough for the parent to fully exit and its listener to shut down.
usleep(500000); // 500ms

// Creating and flushing a span triggers ddtrace_sidecar_ensure_active(), which
// calls the reconnect callback -> dd_sidecar_connect(as_worker=true).
// Since ddog_sidecar_connect_worker(parent_pid) fails and current_pid != master_pid,
// the fix at dd_sidecar_connect promotes this child to master so traces can still
// be submitted.
$span = DDTrace\start_span();
$span->name = 'orphaned-child-span';
DDTrace\close_span();

echo "Child span submitted\n";
exit(0);

?>
--EXPECT--
Child span submitted
