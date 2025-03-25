--TEST--
When _dd.p.ts has more than 8 bits(and less than 32) only 8 bits are printed
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_ORIGIN=datadog
HTTP_X_DATADOG_SAMPLING_PRIORITY=3
HTTP_X_DATADOG_TAGS=_dd.p.ts=FFF
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_APM_TRACING_ENABLED=0
--FILE--
<?php

$outer = DDTrace\start_span();
$outer->name = 'outer';
$inner = DDTrace\start_span();
$inner->name = 'inner';

DDTrace\close_span();
DDTrace\close_span();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
array(2) {
  [0]=>
  array(11) {
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
    string(39) "distributed_trace_asm_standalone_05.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(4) {
      ["_dd.p.ts"]=>
      string(2) "ff"
      ["_dd.p.dm"]=>
      string(2) "-0"
      ["runtime-id"]=>
      string(36) "%s"
      ["_dd.origin"]=>
      string(7) "datadog"
    }
    ["metrics"]=>
    array(6) {
      ["process_id"]=>
      float(%f)
      ["_sampling_priority_v1"]=>
      float(3)
      ["_dd.apm.enabled"]=>
      int(0)
      ["php.compilation.total_time_ms"]=>
      float(%f)
      ["php.memory.peak_usage_bytes"]=>
      float(%f)
      ["php.memory.peak_real_usage_bytes"]=>
      float(%f)
    }
  }
  [1]=>
  array(10) {
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
    string(39) "distributed_trace_asm_standalone_05.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(2) {
      ["_dd.p.ts"]=>
      string(2) "ff"
      ["_dd.origin"]=>
      string(7) "datadog"
    }
  }
}
