--TEST--
[Prehook Regression] DDTrace\trace_function() can trace userland functions with internal spans
--FILE--
<?php
use DDTrace\SpanData;

var_dump(DDTrace\trace_function('filter_to_array', ['prehook' => function (SpanData $span) {
    $span->name = 'filter_to_array';
}]));

function filter_to_array($fn, $input) {
    $output = array();
    foreach ($input as $x) {
        if ($fn($x)) {
            $output[] = $x;
        }
    }
    return $output;
}

$is_odd = function ($x) {
    return $x % 2 == 1;
};

var_export(filter_to_array($is_odd, array(1, 2, 3)));

echo PHP_EOL;

array_map(function($span) {
    echo $span['name'], PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(true)
array (
  0 => 1,
  1 => 3,
)
filter_to_array
