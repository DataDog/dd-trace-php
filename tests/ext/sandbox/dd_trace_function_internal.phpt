--TEST--
DDTrace\trace_function() can trace internal functions with internal spans
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum
--FILE--
<?php
use DDTrace\SpanData;

var_dump(DDTrace\trace_function('array_sum', function (SpanData $span) {
    $span->name = 'ArraySum';
}));

var_dump(array_sum([1, 3, 5]));

echo "---\n";

var_dump(dd_trace_serialize_closed_spans());
var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECTF--
bool(true)
int(9)
---
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
    string(8) "ArraySum"
    ["resource"]=>
    string(8) "ArraySum"
    ["service"]=>
    string(30) "dd_trace_function_internal.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(2) {
      ["system.pid"]=>
      string(%d) "%d"
      ["_dd.p.upstream_services"]=>
      string(50) "ZGRfdHJhY2VfZnVuY3Rpb25faW50ZXJuYWwucGhw|1|1|1.000"
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
array(0) {
}
