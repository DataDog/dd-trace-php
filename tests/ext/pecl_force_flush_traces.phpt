--TEST--
(PECL-only) Force flush the traces mid-way through a trace
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') !== '1') die("skip: pecl-only test");
--ENV--
DD_TRACE_DEBUG=1
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
--EXPECT--
tracing process
process
Flushing trace of size 3 to send-queue for http://localhost:8126
kill
Killed