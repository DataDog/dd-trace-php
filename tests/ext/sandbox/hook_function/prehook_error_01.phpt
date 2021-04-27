--TEST--
DDTrace\hook_function prehook error is sandboxed
--ENV--
DD_TRACE_DEBUG=
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
Hello, Datadog.
