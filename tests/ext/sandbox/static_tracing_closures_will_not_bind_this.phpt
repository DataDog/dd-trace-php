--TEST--
Static tracing closures will not bind $this
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
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

dd_trace_method('Foo', 'test', static function () {
    echo "TRACED Foo::test()\n";
});

$foo = new Foo();
$foo->test();
?>
--EXPECT--
Foo::test()
Cannot trace non-static method with static tracing closure
