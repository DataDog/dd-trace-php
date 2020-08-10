--TEST--
DDTrace\hook_method posthook is passed the correct retval
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

var_dump(DDTrace\hook_method('Greeter', 'greet',
    null,
    function ($This, $scope, $args, $retval) {
        echo "Greeter::greet hooked.\n";
        assert($retval == 1);
    }
));

var_dump(DDTrace\hook_method('SubGreeter', 'greet',
    null,
    function ($This, $scope, $args, $retval) {
        echo "SubGreeter::greet hooked.\n";
        assert($retval === 2);
    }
));

class Greeter
{
    public function greet($name)
    {
        echo "Hello, {$name}.\n";
        return 1;
    }
}

class SubGreeter extends Greeter
{
    public function greet($name)
    {
        echo "Hello, {$name}.\n";
        return 2;
    }
}

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

