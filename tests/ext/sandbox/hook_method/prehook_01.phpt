--TEST--
DDTrace\hook_method prehook
--INI--
ddtrace.request_init_hook=
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

var_dump(DDTrace\hook_method('Greeter', 'greet',
    function ($This, $scope, $args) {
        $id = dd_trace_peek_span_id();
        echo "Greeter::greet prehook. Top span id: {$id}.\n";
    }
));

final class Greeter
{
    public static function greet($name)
    {
        echo "Hello, {$name}.\n";
    }
}

Greeter::greet('Datadog');

?>
--EXPECT--
bool(true)
Greeter::greet prehook. Top span id: 0.
Hello, Datadog.
