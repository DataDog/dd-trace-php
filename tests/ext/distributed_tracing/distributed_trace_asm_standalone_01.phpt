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
  ["attributes"]=>
  array(2) {
    ["_dd.sdk.otlp_export"]=>
    string(5) "false"
    ["_dd.tags.process"]=>
    string(%d) "%s"
  }
  ["chunks"]=>
  array(1) {
    [0]=>
    array(5) {
      ["trace_id"]=>
      string(%d) "%s"
      ["priority"]=>
      int(1)
      ["origin"]=>
      string(7) "datadog"
      ["sampling_mechanism"]=>
      int(0)
      ["spans"]=>
      array(2) {
        [0]=>
        array(9) {
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
            int(%d)
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
        array(9) {
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
          ["attributes"]=>
          array(1) {
            ["_dd.apm.enabled"]=>
            float(0)
          }
        }
      }
    }
  }
}
