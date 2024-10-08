--TEST--
Auto-flushing will sandbox an exception thrown from the tracer flush
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
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
--EXPECTF--
[ddtrace] [info] Flushing trace of size 1 to send-queue for %s
Caught exception: Oops!
[ddtrace] [info] No finished traces to be sent to the agent
