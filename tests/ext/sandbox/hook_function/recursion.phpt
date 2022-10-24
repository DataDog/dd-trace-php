--TEST--
DDTrace\hook_function supports recursion
--FILE--
<?php

DDTrace\hook_function('greet', [
    'prehook' => function ($args) {
        echo "greet prehook: {$args[0]}\n";
    },
    'posthook' => function ($args) {
        echo "greet posthook: {$args[0]}\n";
    },
    'recurse' => true
]);

function greet($name)
{
    echo "Hello, {$name}.\n";
    if ($name === 'Datadog') {
        greet('Woof');
    }
}

greet('Datadog');

?>
--EXPECT--
greet prehook: Datadog
Hello, Datadog.
greet prehook: Woof
Hello, Woof.
greet posthook: Woof
greet posthook: Datadog
