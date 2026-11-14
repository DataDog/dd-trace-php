--TEST--
Numeric keys in span metadata are sent as decimal strings, and null values are skipped
--FILE--
<?php
include 'dd_dumper.inc';
use DDTrace\SpanData;

function meta_to_string() {}

DDTrace\trace_function('meta_to_string', function (SpanData $span) {
    $span->name = 'MetaToString';
    $span->meta = [
        'answer_to_universe' => 42,
        5 => 'five',
        100 => 'one hundred',
        'null_value' => null,
    ];
});

meta_to_string();

list($span) = dd_clean_spans();
unset($span['attributes']['process_id']);
var_dump($span['attributes']);
?>
--EXPECT--
array(3) {
  ["answer_to_universe"]=>
  string(2) "42"
  [5]=>
  string(4) "five"
  [100]=>
  string(11) "one hundred"
}
