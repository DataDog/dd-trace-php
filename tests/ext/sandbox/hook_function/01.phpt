--TEST--
DDTrace\hook_function supports both hooks simultaneously
--FILE--
<?php

DDTrace\hook_function('greet',
    function () {
        echo "greet prehook\n";
    },
    function () {
        echo "greet posthook\n";
    }
);

function greet($name)
{
    echo "Hello, {$name}.\n";
}

greet('Datadog');

?>
--EXPECT--
greet prehook
Hello, Datadog.
greet posthook
