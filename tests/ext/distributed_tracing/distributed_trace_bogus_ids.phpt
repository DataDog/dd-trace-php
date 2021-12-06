--TEST--
Transmit distributed header information to spans
--ENV--
HTTP_X_DATADOG_TRACE_ID=foo
HTTP_X_DATADOG_PARENT_ID=bar
HTTP_X_DATADOG_ORIGIN=datadog
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

$span = DDTrace\start_span();
$span->name = 'span';
DDTrace\close_span();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
array(1) {
  [0]=>
  array(10) {
    ["trace_id"]=>
    string(%d) "%d"
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
    ["meta"]=>
    array(3) {
      ["system.pid"]=>
      string(%d) "%d"
      ["_dd.origin"]=>
      string(7) "datadog"
      ["_dd.p.upstream_services"]=>
      string(52) "ZGlzdHJpYnV0ZWRfdHJhY2VfYm9ndXNfaWRzLnBocA|1|1|1.000"
    }
    ["metrics"]=>
    array(3) {
      ["_dd.rule_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%f)
    }
  }
}
