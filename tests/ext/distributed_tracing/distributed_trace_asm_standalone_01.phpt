--TEST--
Sampling priority is changed to user keep if no asm upstream and asm standalone enabled. Also apm disabled tag is added
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_ORIGIN=datadog
HTTP_X_DATADOG_SAMPLING_PRIORITY=3
HTTP_X_DATADOG_TAGS=_dd.p.custom_tag=inherited,_dd.p.dropped,_dd.p.other_tag=also,_dd.p.drop
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_APM_TRACING_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
include __DIR__ . '/../sandbox/dd_dumper.inc';

$outer = DDTrace\start_span();
$outer->name = 'outer';
$inner = DDTrace\start_span();
$inner->name = 'inner';

DDTrace\close_span();
DDTrace\close_span();

var_dump(dd_clean_spans());

?>
--EXPECTF--
array(2) {
  [0]=>
  array(14) {
    ["trace_id"]=>
    string(2) "42"
    ["span_id"]=>
    string(%d) "%d"
    ["parent_id"]=>
    string(2) "10"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(5) "outer"
    ["resource"]=>
    string(5) "outer"
    ["service"]=>
    string(39) "distributed_trace_asm_standalone_01.php"
    ["type"]=>
    string(3) "cli"
    ["span_kind"]=>
    int(1)
    ["sampling_priority"]=>
    int(1)
    ["sampling_mechanism"]=>
    int(0)
    ["origin"]=>
    string(7) "datadog"
    ["attributes"]=>
    array(9) {
      ["_dd.p.custom_tag"]=>
      string(9) "inherited"
      ["_dd.propagation_error"]=>
      string(14) "decoding_error"
      ["_dd.p.other_tag"]=>
      string(4) "also"
      ["runtime-id"]=>
      string(36) "%s"
      ["process_id"]=>
      float(%f)
      ["_dd.apm.enabled"]=>
      float(0)
      ["php.compilation.total_time_ms"]=>
      float(%f)
      ["php.memory.peak_usage_bytes"]=>
      float(%f)
      ["php.memory.peak_real_usage_bytes"]=>
      float(%f)
    }
  }
  [1]=>
  array(14) {
    ["trace_id"]=>
    string(2) "42"
    ["span_id"]=>
    string(%d) "%d"
    ["parent_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(5) "inner"
    ["resource"]=>
    string(5) "inner"
    ["service"]=>
    string(39) "distributed_trace_asm_standalone_01.php"
    ["type"]=>
    string(3) "cli"
    ["span_kind"]=>
    int(1)
    ["sampling_priority"]=>
    int(1)
    ["sampling_mechanism"]=>
    int(0)
    ["origin"]=>
    string(7) "datadog"
    ["attributes"]=>
    array(1) {
      ["_dd.apm.enabled"]=>
      float(0)
    }
  }
}
