--TEST--
DDTrace\hook_function posthook error is sandboxed
--ENV--
DD_TRACE_DEBUG=
--INI--
error_reporting=E_ALL
--FILE--
<?php

DDTrace\hook_function('greet',
    null,
    function ($args, $retval) {
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
--EXPECT--
Hello, Datadog.
greet hooked.
