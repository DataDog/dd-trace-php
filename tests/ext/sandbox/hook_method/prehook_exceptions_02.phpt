--TEST--
DDTrace\hook_method prehook exception is sandboxed (debug)
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
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
--EXPECTF--
Greeter::greet hooked.
[ddtrace] [warning] Exception thrown in ddtrace's closure defined at %s:%d for Greeter::greet(): !
Hello, Datadog.
Done.
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
