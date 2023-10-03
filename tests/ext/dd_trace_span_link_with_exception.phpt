--TEST--
Span Link serialization with non-null EG(exception) doesn't fail
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

use DDTrace\SpanData;
use DDTrace\SpanLink;

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
    $spanLink = new SpanLink();
    $spanLink->traceId = "42";
    $spanLink->spanId = "6";
    $span->links[] = $spanLink;
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
