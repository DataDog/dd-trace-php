--TEST--
DDTrace\hook_method prehook does not mess up spans with children
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

DDTrace\trace_method('Logger', 'log',
    function ($span) {
        $span->name = $span->resource = 'log';
        $span->service = 'phpt';
    });

DDTrace\hook_method('Greeter', 'greet',
    function () {
        echo "Greeter::greet hooked.\n";
        $topId = dd_trace_peek_span_id();
    });

final class Logger
{
    public static function log($component)
    {
        echo "Component {$component} logged.\n";
    }
}

final class Greeter
{
    public static function greet($name)
    {
        echo "Hello, {$name}.\n";
        Logger::log('Greeter::greet');
    }
}

function main()
{
    Greeter::greet('Datadog');
}

main();

$spans = dd_trace_serialize_closed_spans();
assert(count($spans) == 2);

foreach ($spans as $span) {
    if ($span['span_id'] == $id) {
        continue;
    }
    assert($span['parent_id'] == $id);
}

?>
--EXPECT--
Greeter::greet hooked.
Hello, Datadog.
Component Greeter::greet logged.
