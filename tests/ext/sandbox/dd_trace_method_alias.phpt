--TEST--
dd_trace_method() is aliased to DDTrace\trace_method()
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
use DDTrace\SpanData;

class Foo
{
    public function bar($message)
    {
        echo "Foo::bar($message)\n";
    }
}

dd_trace_method('Foo', 'bar', function (SpanData $span) {
    $span->name = $span->resource = 'Foo.bar';
    $span->service = 'alias';
});

$foo = new Foo();
$foo->bar('hello');

include 'dd_dumper.inc';
dd_dump_spans();
?>
--EXPECTF--
Foo::bar(hello)
spans(\DDTrace\SpanData) (1) {
  Foo.bar (alias, Foo.bar)
    system.pid => %d
}
