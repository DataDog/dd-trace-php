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

$spans = array_merge([], ...array_column(\dd_trace_serialize_closed_spans()['chunks'], 'spans'));
foreach ($spans as &$span) {
    if (isset($span['attributes'])) {
        ksort($span['attributes']);
    }
}
print_r($spans);
