<?php

function foo() {
    usleep(100);
    echo "foo\n";
}

\DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    $span->name = 'foo';
});

foo();
passthru('echo using passthru');

print_r(\dd_trace_serialize_closed_spans());
