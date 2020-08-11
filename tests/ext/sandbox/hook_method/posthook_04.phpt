--TEST--
DDTrace\hook_method posthook is passed the correct args with inheritance
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

var_dump(DDTrace\hook_method('Greeter', 'greet',
    null,
    function ($This, $scope, $args, $retval) {
        echo "Greeter::greet hooked.\n";
        assert($This instanceof Greeter);
        assert($scope == "Greeter");
        assert($args == ["Datadog"]);
        assert($retval == null);
    }
));

var_dump(DDTrace\hook_method('SubGreeter', 'greet',
    null,
    function ($This, $scope, $args, $retval) {
        echo "SubGreeter::greet hooked.\n";
        assert($this instanceof SubGreeter);
        assert($scope == "SubGreeter");
        assert($args == ["Datadog"]);
        assert($retval == null);
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

$greeter = new Greeter();
$greeter->greet('Datadog');

$greeter = new SubGreeter();
$greeter->greet('Datadog');

?>
--EXPECT--
bool(true)
bool(true)
Hello, Datadog.
Greeter::greet hooked.
Hello, Datadog.
SubGreeter::greet hooked.

