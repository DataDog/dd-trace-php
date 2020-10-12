--TEST--
DDTrace\hook_method posthook
--INI--
ddtrace.request_init_hook=
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

var_dump(DDTrace\hook_method('Greeter', 'greet',
    null,
    function ($This, $scope, $args, $retval) {
        $id = dd_trace_peek_span_id();
        echo "Greeter::greet posthook. Top span id: {$id}.\n";
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
Hello, Datadog.
Greeter::greet posthook. Top span id: 0.
