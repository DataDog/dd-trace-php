--TEST--
DDTrace\SpanData::getLink basic functionality
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
--FILE--
<?php

DDTrace\trace_function('greet',
    function (\DDTrace\SpanData $span) {
        echo "greet tracer.\n";
        $span->name = "foo";
        var_dump(json_encode($span->getLink()));
        var_dump(dd_trace_peek_span_id());
        var_dump(\DDTrace\trace_id());
    }
);

function greet($name)
{
    echo "Hello, {$name}.\n";
}

greet('Datadog');

?>
--EXPECTF--
Hello, Datadog.
greet tracer.
string(76) "{"trace_id":"0000000000000000c151df7d6ee5e2d6","span_id":"a3978fb9b92502a8"}"
string(20) "11788048577503494824"
string(20) "13930160852258120406"
