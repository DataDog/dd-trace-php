--TEST--
Check sample rate is in effect
--ENV--
DD_SAMPLING_RATE=0
DD_SPAN_SAMPLING_RULES=[{"sample_rate":1}]
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

ini_set("datadog.span_sampling_rules_file", __DIR__ . "/read-single-span-sampling-config-from-file.config.json");

DDTrace\start_span();
DDTrace\close_span();
$last = dd_trace_serialize_closed_spans()[0]["metrics"];
echo "sampling present after simple span: ";
var_dump(isset($last["_dd.span_sampling.mechanism"]));

$a = DDTrace\start_span();
$a->service = "a";
DDTrace\close_span();
$last = dd_trace_serialize_closed_spans()[0]["metrics"];
echo "sampling present after span of service a: ";
var_dump(isset($last["_dd.span_sampling.mechanism"]));

?>
--EXPECT--
sampling present after simple span: bool(true)
sampling present after span of service a: bool(false)
