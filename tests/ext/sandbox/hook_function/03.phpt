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
--EXPECTF--
DDTrace\hook_function was given neither prehook nor posthook in %s on line %d
bool(false)
Hello, Datadog.
Flushing trace of size 1 to send-queue for %s
