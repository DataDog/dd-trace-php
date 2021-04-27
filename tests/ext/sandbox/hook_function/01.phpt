--TEST--
DDTrace\hook_function supports both hooks simultaneously
--XFAIL--
This is not yet supported
--SKIPIF--
<?php if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pear/pecl test runner does not support XFAIL tests"); ?>
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
