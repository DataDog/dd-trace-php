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
        var_dump($span->getLink());
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
--EXPECT--
Hello, Datadog.
greet tracer.
object(DDTrace\SpanLink)#7 (2) {
  ["traceId"]=>
  string(32) "0000000000000000c151df7d6ee5e2d6"
  ["spanId"]=>
  string(16) "a3978fb9b92502a8"
  ["traceState"]=>
  uninitialized(string)
  ["attributes"]=>
  uninitialized(array)
  ["droppedAttributesCount"]=>
  uninitialized(int)
}
string(20) "11788048577503494824"
string(20) "13930160852258120406"
