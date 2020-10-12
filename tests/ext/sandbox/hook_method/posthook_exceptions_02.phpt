--TEST--
DDTrace\hook_method posthook exception is sandboxed
--FILE--
<?php

DDTrace\hook_method('Greeter', 'greet',
    null,
    function ($This, $scope, $args, $retval) {
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
Hello, Datadog.
Greeter::greet hooked.
Done.
