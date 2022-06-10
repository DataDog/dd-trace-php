--TEST--
dd_trace_method() is aliased to DDTrace\trace_method()
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATE_SERVICE=1
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
  Foo.bar (alias, Foo.bar, cli)
    system.pid => %d
    _dd.p.dm => 1a0a6a36ca-1
    _dd.dm.service_hash => 1a0a6a36ca
}
