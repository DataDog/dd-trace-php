--TEST--
Static tracing closures will not bind $this
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
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
[ddtrace] [info] Flushing trace of size 2 to send-queue for %s
