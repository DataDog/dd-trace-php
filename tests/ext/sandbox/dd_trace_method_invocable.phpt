--TEST--
dd_trace_method() using Invocable Class
--FILE--
<?php
use DDTrace\SpanData;

class Foo
{
    public function bar() {}
}

class Instrumentation
{
    public function __invoke(SpanData $span)
    {
        echo 'This should be called';
    }
}

dd_trace_method('Foo', 'bar', new Instrumentation);

$foo = new Foo();
$foo->bar();
?>
--EXPECTF--
This should be called
