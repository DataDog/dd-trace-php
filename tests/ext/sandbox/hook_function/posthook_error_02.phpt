--TEST--
DDTrace\hook_function posthook error is sandboxed (debug)
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: Test requires internal spans'); ?>
--ENV--
DD_TRACE_DEBUG=1
--INI--
error_reporting=E_ALL
--FILE--
<?php

DDTrace\hook_function('greet',
    null,
    function ($args, $retval) {
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
Hello, Datadog.
greet hooked.
%s in ddtrace's closure for greet(): Undefined variable%sthis_normally_raises_an_%s
Successfully triggered flush with trace of size 1
