--TEST--
DDTrace\hook_method prehook is passed the correct args
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

var_dump(DDTrace\hook_method('Greeter', 'greet',
    function ($This, $scope, $args) {
        echo "Greeter::greet hooked.\n";
        assert($this instanceof Greeter);
        assert($scope == "Greeter");
        assert($args == ["Datadog"]);
    }
));

final class Greeter
{
    public function greet($name)
    {
        echo "Hello, {$name}.\n";
    }
}

$greeter = new Greeter();
$greeter->greet('Datadog');

?>
--EXPECT--
bool(true)
Greeter::greet hooked.
Hello, Datadog.

