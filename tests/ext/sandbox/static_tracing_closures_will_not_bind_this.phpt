--TEST--
Static tracing closures will not bind $this
--ENV--
DD_TRACE_DEBUG=true
--FILE--
<?php
use DDTrace\SpanData;

class Foo
{
    public function test()
    {
        echo "Foo::test()\n";
    }
}

DDTrace\trace_method('Foo', 'test', static function () {
    echo "TRACED Foo::test()\n";
});

$foo = new Foo();
$foo->test();
?>
--EXPECTF--
Foo::test()
TRACED Foo::test()
Flushing trace of size 2 to send-queue for %s
