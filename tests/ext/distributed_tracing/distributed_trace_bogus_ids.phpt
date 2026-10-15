--TEST--
Transmit distributed header information to spans
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
HTTP_X_DATADOG_TRACE_ID=foo
HTTP_X_DATADOG_PARENT_ID=bar
HTTP_X_DATADOG_ORIGIN=datadog
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

$span = DDTrace\start_span();
$span->name = 'span';
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
      array(1) {
        [0]=>
        array(8) {
          ["span_id"]=>
          string(%d) "%d"
          ["start"]=>
          int(%d)
          ["duration"]=>
          int(%d)
          ["name"]=>
          string(4) "span"
          ["resource"]=>
          string(4) "span"
          ["service"]=>
          string(31) "distributed_trace_bogus_ids.php"
          ["type"]=>
          string(3) "cli"
          ["attributes"]=>
          array(6) {
            ["runtime-id"]=>
            string(36) "%s"
            ["process_id"]=>
            int(%d)
            ["_dd.agent_psr"]=>
            float(1)
            ["php.compilation.total_time_ms"]=>
            float(%f)
            ["php.memory.peak_usage_bytes"]=>
            float(%f)
            ["php.memory.peak_real_usage_bytes"]=>
            float(%f)
          }
        }
      }
    }
  }
}