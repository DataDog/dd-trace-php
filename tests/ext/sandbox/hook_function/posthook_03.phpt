--TEST--
DDTrace\hook_function posthook is passed the correct args
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

var_dump(DDTrace\hook_function('greet',
    null,
    function ($args, $retval) {
        echo "greet hooked.\n";
        assert($args == ["Datadog"]);
        assert($retval == null);
    }
));

function greet($name)
{
    echo "Hello, {$name}.\n";
}

greet('Datadog');

?>
--EXPECT--
bool(true)
Hello, Datadog.
greet hooked.

