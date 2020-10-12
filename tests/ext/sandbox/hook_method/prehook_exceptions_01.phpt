--TEST--
DDTrace\hook_method prehook exception is sandboxed
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
Hello, Datadog.
Done.
