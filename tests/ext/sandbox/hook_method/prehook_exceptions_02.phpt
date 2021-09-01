--TEST--
DDTrace\hook_method prehook exception is sandboxed (debug)
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
--ENV--
DD_TRACE_DEBUG=1
--FILE--
<?php

DDTrace\hook_method('Greeter', 'greet',
    function ($This, $scope, $args) {
        echo "Greeter::greet hooked.\n";
        throw new Exception('!');
    }
);

final class Greeter
{
    public function greet($name)
    {
        echo "Hello, {$name}.\n";
    }
}


try {
    $greeter = new Greeter();
    $greeter->greet('Datadog');
    echo "Done.\n";
} catch (Exception $e) {
    echo "Exception caught.\n";
}

?>
--EXPECT--
Greeter::greet hooked.
Exception thrown in ddtrace's closure for Greeter::greet(): !
Hello, Datadog.
Done.
Successfully triggered flush with trace of size 1
