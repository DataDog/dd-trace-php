--TEST--
DDTrace\hook_function posthook basic functionality
--INI--
ddtrace.request_init_hook=
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

var_dump(DDTrace\hook_function('greet',
    null,
    function ($args, $retval) {
        $id = dd_trace_peek_span_id();
        echo "greet posthook. Top span id: {$id}.\n";
    }
));

function greet($name)
{
    echo "Hello, {$name}.\n";
}

greet('Datadog');

?>
--EXPECT--
bool(true)
Hello, Datadog.
greet posthook. Top span id: 0.
