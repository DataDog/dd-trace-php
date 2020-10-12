--TEST--
DDTrace\hook_method supports both hooks simultaneously
--XFAIL--
This is not yet supported
--SKIPIF--
<?php if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pear/pecl test runner does not support XFAIL tests"); ?>
--FILE--
<?php

DDTrace\hook_method('Greeter', 'greet',
    function () {
        echo "Greeter::greet prehook\n";
    },
    function () {
        echo "Greeter::greet posthook\n";
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
--EXPECT--
Greeter::greet prehook
Hello, Datadog.
Greeter::greet posthook
