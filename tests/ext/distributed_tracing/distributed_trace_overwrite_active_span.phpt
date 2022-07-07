--TEST--
Setting a distributed tracing context if a span is already active
--ENV--
HTTP_X_DATADOG_TAGS=custom_tag=inherited
HTTP_X_DATADOG_ORIGIN=datadog
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

DDTrace\start_span();
DDTrace\start_span();

var_dump(DDTrace\set_distributed_tracing_context("123", "321", "foo", ["a" => "b"]));
var_dump(DDtrace\current_context());
var_dump(DDtrace\current_context()["span_id"] != "123" && DDtrace\current_context()["span_id"] != "321");

DDTrace\close_span();
DDTrace\close_span();

foreach (dd_trace_serialize_closed_spans() as $span) {
    unset($span["meta"]["system.pid"], $span["meta"]["_dd.p.dm"]);
    echo "parent: {$span["parent_id"]}, trace: {$span["trace_id"]}, meta: " . json_encode($span["meta"]) . "\n";
}

?>
--EXPECTF--
bool(true)
array(7) {
  ["trace_id"]=>
  string(3) "123"
  ["span_id"]=>
  string(%d) "%d"
  ["version"]=>
  NULL
  ["env"]=>
  NULL
  ["distributed_tracing_origin"]=>
  string(3) "foo"
  ["distributed_tracing_parent_id"]=>
  string(3) "321"
  ["distributed_tracing_propagated_tags"]=>
  array(1) {
    ["a"]=>
    string(1) "b"
  }
}
bool(true)
parent: 321, trace: 123, meta: {"_dd.origin":"foo","a":"b"}
parent: %d, trace: 123, meta: {"_dd.origin":"foo"}
