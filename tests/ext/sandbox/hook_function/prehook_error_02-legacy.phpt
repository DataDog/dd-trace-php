--TEST--
DDTrace\hook_function prehook error is sandboxed (debug)
--SKIPIF--
<?php if (PHP_VERSION_ID >= 70000) die('skip: Test does not work with internal spans'); ?>
--ENV--
DD_TRACE_DEBUG=1
--INI--
error_reporting=E_ALL
--FILE--
<?php

DDTrace\hook_function('greet',
    function ($args) {
        echo "greet hooked.\n";
        $i = $this_normally_raises_an_error;
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
%s in ddtrace's closure for greet(): Undefined variable%sthis_normally_raises_an_%s
Hello, Datadog.
