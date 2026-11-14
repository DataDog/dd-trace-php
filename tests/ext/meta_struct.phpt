--TEST--
SpanData::$meta_struct is deprecated and ignored: nothing written to it is sent
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
$span = DDTrace\start_span();
$span->meta_struct["foo"] = "bar";
$span->meta_struct["john"] = ["Doe"];
$span->meta_struct[""] = "e";
$span->meta_struct[7] = "n";
// Composite values are sent as nested attributes instead.
$span->attributes["_dd.stack"] = ["exploit" => [["id" => 1]]];
var_dump(count($span->meta_struct));
DDTrace\close_span();

$span = dd_trace_serialize_closed_spans()['chunks'][0]['spans'][0];
var_dump(isset($span["meta_struct"]));
var_dump(array_intersect_key($span["attributes"], array_flip(["foo", "john", "", "7", "_dd.stack"])));
?>
--EXPECT--
int(4)
bool(false)
array(1) {
  ["_dd.stack"]=>
  array(1) {
    ["exploit"]=>
    array(1) {
      [0]=>
      array(1) {
        ["id"]=>
        int(1)
      }
    }
  }
}
