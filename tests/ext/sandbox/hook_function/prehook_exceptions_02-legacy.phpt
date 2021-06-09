--TEST--
DDTrace\hook_function prehook exception is sandboxed (debug)
--SKIPIF--
<?php if (PHP_VERSION_ID >= 80000) die('skip: Test does not work with internal spans'); ?>
--ENV--
DD_TRACE_DEBUG=1
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

DDTrace\hook_function('greet',
    function ($args) {
        echo "greet hooked.\n";
        assert($args == ['Datadog']);
        throw new Exception('!');
    }
);

function greet($name)
{
    echo "Hello, {$name}.\n";
}

try {
    greet('Datadog');
    echo "Done.\n";
} catch (Exception $e) {
    echo "Exception caught.\n";
}

?>
--EXPECT--
greet hooked.
Exception thrown in ddtrace's closure for greet(): !
Hello, Datadog.
Done.
