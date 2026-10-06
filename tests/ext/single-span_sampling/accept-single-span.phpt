--TEST--
Ingest all spans
--ENV--
DD_TRACE_SAMPLE_RATE=0
DD_SPAN_SAMPLING_RULES=[{"sample_rate":1}]
--FILE--
<?php

DDTrace\start_span();
DDTrace\close_span();

$attributes = dd_trace_serialize_closed_spans()[0]["attributes"];
var_dump([
    "_dd.span_sampling.mechanism" => $attributes["_dd.span_sampling.mechanism"],
    "_dd.span_sampling.rule_rate" => $attributes["_dd.span_sampling.rule_rate"],
]);

?>
--EXPECT--
array(2) {
  ["_dd.span_sampling.mechanism"]=>
  float(8)
  ["_dd.span_sampling.rule_rate"]=>
  float(1)
}
