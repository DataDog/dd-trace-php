--TEST--
DDTrace\hook_method posthook is called with an exception; args are still good
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

DDTrace\hook_method('Greeter', 'greet',
    null,
    function ($This, $scope, $args, $retval) {
        echo "Greeter::greet hooked.\n";
	assert($This instanceof Greeter);
	assert($scope === 'Greeter');
        assert($args === ['Datadog']);
        assert($retval === null);
    }
);

final class Greeter
{
    public function greet($name)
    {
        echo "Hello, {$name}.\n";
        throw new Exception("!");
    }
}


try {
    $greeter = new Greeter();
    $greeter->greet('Datadog');
} catch (Exception $e) {
    echo "Exception caught.\n";
    assert($e->getMessage() == "!");
}

?>
--EXPECT--
Hello, Datadog.
Greeter::greet hooked.
Exception caught.
