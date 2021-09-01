--TEST--
Auto-flushing will sandbox an exception thrown from the tracer flush
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: Test requires internal spans'); ?>
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_AUTO_FLUSH_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use DDTrace\SpanData;

class Foo
{
    public function bar()
    {
        $this->doException();
    }

    private function doException()
    {
        throw new Exception('Oops!');
    }
}

DDTrace\trace_method('Foo', 'bar', function (SpanData $span) {
    $span->name = 'Foo.bar';
});

$foo = new Foo();
try {
    $foo->bar();
} catch (Exception $e) {
    echo 'Caught exception: ' . $e->getMessage() . PHP_EOL;
}
?>
--EXPECT--
Successfully triggered flush with trace of size 1
Caught exception: Oops!
No finished traces to be sent to the agent
