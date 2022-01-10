--TEST--
Transmit distributed header information to spans
--ENV--
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_ORIGIN=datadog
DD_TRACE_GENERATE_ROOT_SPAN=0
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
    string(29) "distributed_trace_inherit.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(2) {
      ["system.pid"]=>
      string(%d) "%d"
      ["_dd.origin"]=>
      string(7) "datadog"
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
    string(29) "distributed_trace_inherit.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(1) {
      ["_dd.origin"]=>
      string(7) "datadog"
    }
  }
}
