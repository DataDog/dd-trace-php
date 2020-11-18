--TEST--
DDTrace\hook_method prehook is passed the correct args with inheritance
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: Dispatch can be overwritten on PHP < 8'); ?>
--ENV--
DD_TRACE_DEBUG=1
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

var_dump(DDTrace\hook_method('Greeter', 'greet',
    function ($obj, $scope, $args) {
        echo "Greeter::greet hooked.\n";
        assert($obj instanceof Greeter);
        assert($args === ["Datadog"]);
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

var_dump(DDTrace\hook_method('Greeter', 'greet',
    function ($obj, $scope, $args) {
        echo "Greeter::greet hooked.\n";
        assert($obj instanceof Greeter);
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
Cannot overwrite existing dispatch for 'greet()'
bool(false)
Greeter::greet hooked.
Hello, Datadog.
