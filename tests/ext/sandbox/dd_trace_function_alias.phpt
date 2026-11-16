--TEST--
dd_trace_function() is a deprecated alias of DDTrace\trace_function()
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--INI--
error_reporting=E_ALL
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
Deprecated: Function dd_trace_function() is deprecated in %s on line %d
bar(hello)
spans(\DDTrace\SpanData) (1) {
  bar (alias, bar, cli)
    _dd.svc_src => m
}
