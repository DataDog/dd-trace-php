--TEST--
DDTrace\hook_method prehook error is sandboxed
--ENV--
DD_TRACE_DEBUG=
--INI--
error_reporting=E_ALL
--FILE--
<?php

DDTrace\hook_method('Greeter', 'greet',
    function ($This, $scope, $args) {
        echo "{$scope}::greet hooked.\n";
        $i = $this_normally_raises_a_notice; // E_NOTICE
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
Hello, Datadog.
