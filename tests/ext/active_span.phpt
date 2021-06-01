--TEST--
DDTrace\active_span basic functionality
--INI--
--FILE--
<?php

DDTrace\trace_function('greet',
    function ($span) {
        echo "greet tracer.\n";
        $span->name = "foo";
        var_dump($span == DDTrace\active_span());
    }
);

function greet($name)
{
    echo "Hello, {$name}.\n";
}

greet('Datadog');

var_dump(DDTrace\active_span());
var_dump(DDTrace\active_span() == DDTrace\active_span());

?>
--EXPECT--
Hello, Datadog.
greet tracer.
bool(true)
object(DDTrace\SpanData)#1 (0) {
}
bool(true)
