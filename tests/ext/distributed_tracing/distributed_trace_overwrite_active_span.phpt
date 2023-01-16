--TEST--
Setting a distributed tracing context if a span is already active
--ENV--
HTTP_X_DATADOG_TAGS=custom_tag=inherited
HTTP_X_DATADOG_ORIGIN=datadog
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

function dump_spans() {
    foreach (dd_trace_serialize_closed_spans() as $span) {
        unset($span["meta"]["process_id"], $span["meta"]["_dd.p.dm"]);
        echo "parent: ", $span["parent_id"] ?? 0, ", trace: {$span["trace_id"]}, meta: " . json_encode($span["meta"] ?? []) . "\n";
    }
    return $span;
}

DDTrace\start_span();
DDTrace\start_span();

var_dump(DDTrace\set_distributed_tracing_context("123", "321", "foo", ["a" => "b"]));
var_dump(DDTrace\current_context());
var_dump(DDTrace\current_context()["span_id"] != "123" && DDtrace\current_context()["span_id"] != "321");

DDTrace\close_span();
DDTrace\close_span();

dump_spans();

DDTrace\start_span();
DDTrace\start_span();

var_dump(DDTrace\set_distributed_tracing_context("0", "0", "", []));
var_dump(DDTrace\current_context());
$trace_id = DDTrace\trace_id();
var_dump($trace_id != 123);
var_dump($trace_id == DDTrace\root_span()->id);

DDTrace\close_span();
DDTrace\close_span();

$span = dump_spans();
echo "all spans trace_id updated: "; var_dump($span["trace_id"] == $trace_id);

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
bool(true)
array(5) {
  ["trace_id"]=>
  string(%d) "%d"
  ["span_id"]=>
  string(%d) "%d"
  ["version"]=>
  NULL
  ["env"]=>
  NULL
  ["distributed_tracing_propagated_tags"]=>
  array(0) {
  }
}
bool(true)
bool(true)
parent: 0, trace: %d, meta: []
parent: %d, trace: %d, meta: []
all spans trace_id updated: bool(true)
