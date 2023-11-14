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
        $link = $span->getLink();
        var_dump(json_encode($link));
        var_dump(\DDTrace\active_span()->hexId() === $link->spanId);
        var_dump(\DDTrace\root_span()->traceId === $link->traceId);
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
string(%d) "{"trace_id":"%s","span_id":"%s"}"
bool(true)
bool(true)
