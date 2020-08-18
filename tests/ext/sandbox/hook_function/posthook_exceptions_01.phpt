--TEST--
DDTrace\hook_function posthook is called with an exception; args are still good
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

DDTrace\hook_function('greet',
    null,
    function ($args, $retval) {
        echo "greet hooked.\n";
        assert($args == ['Datadog']);
        assert($retval === null);
    }
);

function greet($name)
{
    echo "Hello, {$name}.\n";
    throw new Exception("!");
}


try {
    greet('Datadog');
} catch (Exception $e) {
    echo "Exception caught.\n";
    assert($e->getMessage() == "!");
}

?>
--EXPECT--
Hello, Datadog.
greet hooked.
Exception caught.
