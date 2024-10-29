--TEST--
Foo
--ENV--
DD_SERVICE=version_test
DD_VERSION=5.2.0
DD_TRACE_DEBUG=1
--FILE--
<?php

$s1 = \DDTrace\start_trace_span();
$s1->name = "s1";
\DDTrace\close_span();

$s2 = \DDTrace\start_trace_span();
$s2->name = "s2";
$s2->service = "no dd_service";
\DDTrace\close_span();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
