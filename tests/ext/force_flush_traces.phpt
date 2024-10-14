--TEST--
Force flush the traces mid-way through a trace
--SKIPIF--
<?php
if (!function_exists('posix_kill')) die('skip: posix_kill not available');
if (getenv('PHP_PEAR_RUNTESTS') === '1') die('skip: Pear/RunTest.php does not support %r...%r tags');
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_GENERATE_ROOT_SPAN=1
--FILE--
<?php

function kill() {
    echo "kill\n";
    posix_kill(posix_getpid(), SIGKILL);
}

function process() {
    echo "process\n";
    kill();
}

DDTrace\trace_function('process', [
    'prehook' => function () {
        \DDTrace\start_trace_span();
        echo "tracing process\n";
    },
    'posthook' => function () {
        \DDTrace\close_span();
        echo "never called\n";
    }
]);

DDTrace\hook_function('kill', function () {
    ini_set('datadog.autofinish_spans', '1');
    dd_trace_close_all_spans_and_flush(); // 3: root span + process + start_trace_span
});

process();

var_dump(dd_trace_serialize_closed_spans()); // Spans should be flushed, so this should be empty
--EXPECTF--
tracing process
process
[ddtrace] [info] Flushing trace of size 3 to send-queue for %s
kill%r\n*(Killed\n*)?(Termsig=9)?%r
