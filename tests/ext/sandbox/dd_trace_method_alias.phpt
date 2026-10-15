--TEST--
dd_trace_method() is a deprecated alias of DDTrace\trace_method()
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--INI--
error_reporting=E_ALL
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
Deprecated: Function dd_trace_method() is deprecated in %s on line %d
Foo::bar(hello)
spans(\DDTrace\SpanData) (1) {
  Foo.bar (alias, Foo.bar, cli)
    _dd.svc_src => m
}
