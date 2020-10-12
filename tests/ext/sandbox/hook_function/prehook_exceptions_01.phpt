--TEST--
DDTrace\hook_function prehook exception is sandboxed
--FILE--
<?php

DDTrace\hook_function('greet',
    function ($args) {
        echo "greet hooked.\n";
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
Hello, Datadog.
Done.
