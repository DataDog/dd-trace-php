--TEST--
DDTrace\trace_function() can trace userland functions with internal spans
--FILE--
<?php
use DDTrace\SpanData;

var_dump(DDTrace\trace_function('filter_to_array', function (SpanData $span) {
    $span->name = 'filter_to_array';
}));

function filter_to_array($fn, $input) {
    $output = array();
    foreach ($input as $x) {
        if (call_user_func($fn, $x)) {
            $output[] = $x;
        }
    }
    return $output;
}

$is_odd = function ($x) {
    return $x % 2 == 1;
};

var_export(filter_to_array($is_odd, array(1, 2, 3)));

echo "\n---\n";

var_dump(dd_trace_serialize_closed_spans());
var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECTF--
bool(true)
array (
  0 => 1,
  1 => 3,
)
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
    string(15) "filter_to_array"
    ["resource"]=>
    string(15) "filter_to_array"
    ["meta"]=>
    array(1) {
      ["system.pid"]=>
      string(%d) "%d"
    }
  }
}
array(0) {
}
