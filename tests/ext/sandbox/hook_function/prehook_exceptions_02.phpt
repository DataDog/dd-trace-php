--TEST--
DDTrace\hook_function prehook exception is sandboxed (debug)
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
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
--EXPECTF--
greet hooked.
[ddtrace] [warning] Exception thrown in ddtrace's closure defined at %s:%d for greet(): ! in %s on line %d
Hello, Datadog.
Done.
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
