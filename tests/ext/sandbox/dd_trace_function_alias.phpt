--TEST--
dd_trace_function() is aliased to DDTrace\trace_function()
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use DDTrace\SpanData;

function bar($message)
{
    echo "bar($message)\n";
}

dd_trace_function('bar', function (SpanData $span) {
    $span->name = $span->resource = 'bar';
    $span->service = 'alias';
});

bar('hello');

include 'dd_dumper.inc';
dd_dump_spans();
?>
--EXPECTF--
bar(hello)
spans(\DDTrace\SpanData) (1) {
  bar (alias, bar, cli)
    system.pid => %d
    _dd.p.upstream_services => YWxpYXM|1|1|1.000
}
