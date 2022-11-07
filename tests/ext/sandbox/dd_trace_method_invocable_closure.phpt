--TEST--
dd_trace_method() using Invocable Closure
--FILE--
<?php
use DDTrace\SpanData;

class Foo
{
    public function bar() {}
}

class Instrumentation
{
    public function __construct(private string $context) {}

    public function __invoke(SpanData $span)
    {
        echo 'This works' . PHP_EOL;

        echo 'This fails: ' . $this->context;
    }
}

dd_trace_method('Foo', 'bar', \Closure::fromCallable(new Instrumentation('binding')));

$foo = new Foo();
$foo->bar();
?>
--EXPECTF--
This works
This fails: binding
