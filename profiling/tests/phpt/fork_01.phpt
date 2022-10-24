--TEST--
[profiling] test that the profiler doesn't crash on pcntl_fork
--DESCRIPTION--
The profiler does not yet support forking, but it shouldn't crash if the
process is forked. Logging to stderr will acquire a lock, so that's why the
log level is set for this test to the highest setting, to hopefully provide
opportunities to lock or crash if that lock is held.
--SKIPIF--
<?php
foreach (['datadog-profiling', 'pcntl'] as $extension)
    if (!extension_loaded($extension))
        echo "skip: test requires {$extension}\n";
?>
--INI--
assert.exception=1
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_LOG_LEVEL=debug
--FILE--
<?php
// sleep to simulate the process doing something prior to the fork
usleep($microseconds = 10000);

echo "Forking.\n";
$pid = pcntl_fork();
assert($pid != -1);

if ($pid == 0) { // the child has pid of 0
    usleep($microseconds = 10000);
} else {
    $status = 0;
    pcntl_wait($status);
    $exit_code = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 1;
    if ($exit_code === 0) {
        echo "Child exited successfully.\n";
    }
    exit($exit_code);
}

?>
--EXPECTREGEX--
.*
Child exited successfully.*
