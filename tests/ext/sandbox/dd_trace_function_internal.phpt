--TEST--
DDTrace\trace_function() can trace internal functions with internal spans
--ENV--
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
  array(7) {
    ["trace_id"]=>
    int(%d)
    ["span_id"]=>
    int(%d)
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(8) "ArraySum"
    ["resource"]=>
    string(8) "ArraySum"
    ["meta"]=>
    array(1) {
      ["system.pid"]=>
      string(%d) "%d"
    }
  }
}
array(0) {
}
