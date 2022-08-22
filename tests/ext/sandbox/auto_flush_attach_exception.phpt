--TEST--
Auto-flushing will attach an exception during exception cleanup
--DESCRIPTION--
@see https://github.com/DataDog/dd-trace-php/issues/879
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=1
DD_TRACE_DEBUG=1
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
Caught exception: Oops!
Flushing trace of size 2 to send-queue for %s
No finished traces to be sent to the agent
