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
        assert($This instanceof $args[0]);
        assert($scope == $args[0]);
        assert($retval == null);
    }
));

var_dump(DDTrace\hook_method('SubGreeter', 'greet',
    null,
    function ($This, $scope, $args, $retval) {
        echo "SubGreeter::greet hooked.\n";
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
$greeter->greet('Greeter');

$greeter = new SubGreeter();
$greeter->greet('SubGreeter');

?>
--EXPECT--
bool(true)
bool(true)
Hello, Greeter.
SubGreeter::greet hooked.
Greeter::greet hooked.
Hello, SubGreeter.
SubGreeter::greet hooked.
Greeter::greet hooked.

