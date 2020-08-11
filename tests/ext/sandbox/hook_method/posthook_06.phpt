--TEST--
Nested closure targeting method call 01 (posthook)
--DESCRIPTION--
In PHP 5 you cannot bind a static closure to an object. It's not always
obvious when a closure is automatically static.

In this one, we're making sure that a tracing closure still works when:
  - It is defined inside another closure that doesn't have a scope.
  - It is targeting a method.
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

DDTrace\hook_method('Greeter', '__construct',
    null,
    function ($This, $scope, $args) {
        DDTrace\hook_method('Greeter', 'greet',
            function ($This, $scope, $args) {
                echo "Greeter::greet hooked.\n";
            });

        DDTrace\trace_method('Greeter', 'thank',
            function ($span, $args, $retval, $exception) {
                $span->name = $span->resource = 'Greeter::thank';
                $span->service = 'phpt';
                echo "Greeter::thank traced.\n";
                return false;
            });

        echo "Greeter::__construct hooked.\n";
    });

final class Greeter
{
    // just here to set up tracing
    public function __construct() {}

    public function greet($name)
    {
        echo "Hello, {$name}.\n";
    }

    public function thank($name)
    {
        echo "Thank you, {$name}.\n";
    }

}

$greeter = new Greeter();
$greeter->greet('Datadog');
$greeter->thank('Datadog');

?>
--EXPECT--
Greeter::__construct hooked.
Greeter::greet hooked.
Hello, Datadog.
Thank you, Datadog.
Greeter::thank traced.
