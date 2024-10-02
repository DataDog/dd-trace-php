--TEST--
DDTrace\hook_method prehook error is sandboxed (debug)
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
--INI--
error_reporting=E_ALL
--FILE--
<?php

DDTrace\hook_method('Greeter', 'greet',
    function ($args) {
        echo "Greeter::greet hooked.\n";
        $i = $this_normally_raises_an_error;
    }
);


final class Greeter
{
    public static function greet($name)
    {
        echo "Hello, {$name}.\n";
    }
}

Greeter::greet('Datadog');

?>
--EXPECTF--
Greeter::greet hooked.
[ddtrace] [warning] %s in ddtrace's closure defined at %s:%d for Greeter::greet(): Undefined variable%sthis_normally_raises_an_%s
Hello, Datadog.
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
