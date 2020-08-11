--TEST--
DDTrace\hook_function prehook error is sandboxed (debug)
--ENV--
DD_TRACE_DEBUG=1
--INI--
error_reporting=E_ALL
--FILE--
<?php

DDTrace\hook_function('greet',
    function ($args) {
        echo "greet hooked.\n";
        $i = $this_normally_raises_a_notice; // E_NOTICE
    }
);

function greet($name)
{
    echo "Hello, {$name}.\n";
}

greet('Datadog');

?>
--EXPECTF--
greet hooked.
Error raised in ddtrace's closure for greet(): Undefined variable: this_normally_raises_a_notice in %s on line %d
Hello, Datadog.
