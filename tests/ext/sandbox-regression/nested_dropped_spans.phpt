--TEST--
[regression] Properly skip nested dropped spans
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

function foo() {
    DDTrace\start_span()->name = "inner span";
    DDTrace\close_span();
}

function bar() {
    foo();
}

DDTrace\trace_function('foo', function() { return false; });
DDTrace\trace_function('bar', function() { return false; });

DDTrace\start_span()->name = "root span";
bar();
DDTrace\close_span();

include __DIR__ . '/../sandbox/dd_dumper.inc';
dd_dump_spans();

?>
--EXPECTF--
spans(\DDTrace\SpanData) (1) {
  root span (nested_dropped_spans.php, root span, cli)
    _dd.p.dm => -1
    inner span (nested_dropped_spans.php, inner span, cli)
}
