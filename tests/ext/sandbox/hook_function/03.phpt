--TEST--
DDTrace\hook_function returns false with diagnostic when no hook is passed
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php

var_dump(DDTrace\hook_function('greet'));

function greet($name)
{
    echo "Hello, {$name}.\n";
}

greet('Datadog');

?>
--EXPECT--
DDTrace\hook_function was given neither prehook nor posthook.
bool(false)
Hello, Datadog.
