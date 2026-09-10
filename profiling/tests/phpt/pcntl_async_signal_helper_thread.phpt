--TEST--
[profiling] async PHP signal handlers must not run on profiler helper threads
--DESCRIPTION--
Regression test for a crash when a PHP script installs an async signal handler
(pcntl_async_signals(true) + pcntl_signal()) for a signal the Zend Engine does
not itself register, e.g. SIGCHLD.

The profiler's helper threads (`ddprof_time`, `ddprof_upload`) only masked the
fixed set of signals the Zend Engine uses, so the kernel could deliver SIGCHLD
to one of them. pcntl's handler then ran on a thread with no valid PHP/TSRM
context and dereferenced the thread-local PCNTL_G, segfaulting. The fix is to
block every (non-fault) signal on the helper threads so async signals are
delivered to a PHP thread.

Only crashes on ZTS, where PCNTL_G is thread-local. Observed on PHP 8.4 ZTS,
reproduced by ext/pcntl/tests/waiting_on_sigchild_pcntl_wait.phpt:

    Thread 2 "ddprof_time" received signal SIGSEGV, Segmentation fault.
    #0  pcntl_signal_handler (signo=17, ...) at ext/pcntl/pcntl.c:1289
    1289        struct php_pcntl_pending_signal *psig = PCNTL_G(spares);
    #1  <signal handler called>
    #2  syscall ()
    #3  std::sys::pal::unix::futex::futex_wait ()
    #4  std::sys::sync::thread_parking::futex::Parker::park_timeout ()
    ...
    #15 run () at profiling/src/profiler/uploader.rs:157
    #16 {closure#3} () at profiling/src/profiler/mod.rs:898
    #17 ... at profiling/src/profiler/thread_utils.rs:45
--SKIPIF--
<?php
if (!(extension_loaded('datadog-profiling') || ini_get('datadog.profiling.enabled') !== false))
    echo "skip: test requires Datadog profiling support\n";
if (!extension_loaded('pcntl'))
    echo "skip: test requires pcntl\n";
if (!ZEND_THREAD_SAFE)
    echo "skip: ZTS only (the crash is a thread-local PCNTL_G access from a helper thread)\n";
if (PHP_OS_FAMILY !== 'Linux')
    echo "skip: Linux only\n";
if (getenv('SKIP_ASAN'))
    die('skip: the profiler leaks on purpose in child of a fork');
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_LOG_LEVEL=off
; This test is specifically about the profiler's own helper threads
; (ddprof_time/ddprof_upload) not eating async signals meant for a PHP
; thread -- it is not about the tracer/sidecar/telemetry. Since CI now only
; builds and tests the combined ddtrace.so, all of that machinery starts up
; here too purely as a side effect of the extension being combined, adding
; background threads/a sidecar connection attempt/CPU contention that has
; nothing to do with what this test verifies and makes its timing-sensitive
; SIGCHLD-reaping loop flakier on constrained CI runners. Disable every
; flag that gates datadog_sidecar_should_enable() (see ext/sidecar.c) so no
; sidecar connection is attempted at all, keeping this test's timing budget
; focused on the profiler. DD_TRACE_ENABLED is deliberately not part of
; that gate (see datadog_sidecar_should_enable()), so it is not listed here.
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
DD_EXPERIMENTAL_FLAGGING_PROVIDER_ENABLED=0
DD_METRICS_OTEL_ENABLED=0
--FILE--
<?php

pcntl_async_signals(true);

$processes = [];
pcntl_signal(SIGCHLD, function () use (&$processes) {
    while (($pid = pcntl_wait($status, WUNTRACED | WNOHANG)) > 0) {
        unset($processes[$pid]);
    }
});

// Spawn bursts of short-lived children. They exit at roughly the same time, so
// a burst of SIGCHLD arrives while the main thread is idle in usleep(). If the
// profiler's helper threads do not block SIGCHLD the kernel may deliver it to
// one of them, where pcntl's handler runs without a PHP/TSRM context and
// crashes. Several rounds widen the (timing-dependent) race window.
for ($round = 0; $round < 4; $round++) {
    for ($i = 0; $i < 8; $i++) {
        $proc = proc_open('sleep 0.3', [], $pipes);
        if ($proc !== false) {
            $processes[proc_get_status($proc)['pid']] = $proc;
        }
    }
    $iters = 50;
    while (!empty($processes) && $iters-- > 0) {
        usleep(100000);
    }
}

echo empty($processes) ? "OK\n" : "leftover children\n";
?>
--EXPECT--
OK
