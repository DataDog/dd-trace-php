--TEST--
DDTrace\hook_method prehook is passed the correct args with inheritance
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

var_dump(DDTrace\hook_method('Greeter', 'greet',
    function ($This, $scope, $args) {
        echo "Greeter::greet hooked.\n";
        assert($this instanceof SubGreeter);
        assert($scope == "SubGreeter");
        assert($args == ["Datadog"]);
    }
));

class Greeter
{
    public function greet($name)
    {
        echo "Hello, {$name}.\n";
    }
}

class SubGreeter extends Greeter {}

$greeter = new SubGreeter();
$greeter->greet('Datadog');

dd_untrace('greet', 'Greeter');

var_dump(DDTrace\hook_method('Greeter', 'greet',
    function ($This, $scope, $args) {
        echo "Greeter::greet hooked.\n";
        assert($This instanceof Greeter);
        assert($scope == "Greeter");
        assert($args == ["Datadog"]);
    }
));
$greeter = new Greeter();
$greeter->greet('Datadog');

?>
--EXPECT--
bool(true)
Greeter::greet hooked.
Hello, Datadog.
bool(true)
Greeter::greet hooked.
Hello, Datadog.

