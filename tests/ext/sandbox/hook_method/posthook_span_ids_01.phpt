--TEST--
DDTrace\hook_method posthook does not mess up span ids
--INI--
zend.assertions=1
assert.exception=1
ddtrace.request_init_hook=
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

$id = 0;
DDTrace\trace_function('main',
    function ($span) use (&$id) {
        $span->name = $span->resource = 'main';
        $span->service = 'phpt';
        $id = dd_trace_peek_span_id();
        assert($id != 0);
    });

DDTrace\hook_method('Greeter', 'greet',
    null,
    function () use (&$id) {
        echo "Greeter::greet hooked.\n";
        $topId = dd_trace_peek_span_id();
        assert($topId != 0);
        assert($topId != $id);
    });

final class Greeter
{
    public static function greet($name)
    {
        echo "Hello, {$name}.\n";
    }
}

function main() {
    Greeter::greet('Datadog');
}

main();

?>
--EXPECT--
Hello, Datadog.
Greeter::greet hooked.
