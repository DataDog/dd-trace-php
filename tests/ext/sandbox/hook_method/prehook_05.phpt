--TEST--
A call to trace_method from hook_method prehook has non-static closure
--DESCRIPTION--
In PHP 5 you cannot bind a static closure to an object. It's not always
obvious when a closure is automatically static. This is one case that was
caught in integration tests in real-world usage: the non-tracing hook is used
to set up the remainder of the integration.
--INI--
zend.assertions=1
assert.exception=1
--FILE--
<?php

DDTrace\hook_method('Greeter', 'greet',
    function ($This, $scope, $args) {
        echo "Greeter::greet hooked.\n";

        DDTrace\trace_method('Greeter', 'emit',
            function ($span, $args, $retval, $exception) {
                $span->name = $span->resource = 'Greeter::emit';
                $span->service = 'phpt';
                echo "Greeter::emit traced.\n";
            });
    });

final class Greeter
{
    private function emit($message)
    {
        echo $message;
    }

    public function greet($name)
    {
        $this->emit("Hello, {$name}.\n");
    }
}

$greeter = new Greeter();
$greeter->greet('Datadog');

?>
--EXPECT--
Greeter::greet hooked.
Hello, Datadog.
Greeter::emit traced.
