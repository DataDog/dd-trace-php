--TEST--
DDTrace\hook_function returns false with diagnostic when no hook is passed
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
--FILE--
<?php

var_dump(DDTrace\hook_function('greet'));

function greet($name)
{
    echo "Hello, {$name}.\n";
}

greet('Datadog');

?>
--EXPECTF--
[ddtrace] [warning] DDTrace\hook_function was given neither prehook nor posthook in %s on line %d; This message is only displayed once. Specify DD_TRACE_ONCE_LOGS=0 to show all messages.
bool(false)
Hello, Datadog.
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
