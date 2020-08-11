--TEST--
DDTrace\hook_method returns false quietly when no hook is passed
--ENV--
DD_TRACE_DEBUG=0
--FILE--
<?php

var_dump(DDTrace\hook_method('Greeter', 'greet'));

final class Greeter
{
    public static function greet($name)
    {
        echo "Hello, {$name}.\n";
    }
}

Greeter::greet('Datadog');

?>
--EXPECT--
bool(false)
Hello, Datadog.
