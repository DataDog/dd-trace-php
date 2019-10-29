--TEST--
Invalid (non-string) keys in span metadata are dropped
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
use DDTrace\SpanData;

function meta_to_string() {}

dd_trace_function('meta_to_string', function (SpanData $span) {
    $span->name = 'MetaToString';
    $span->meta = [
        'answer_to_universe' => 42,
        5 => 'should be dropped',
        100 => 'should be dropped as well',
        'null_value' => null,
    ];
});

meta_to_string();

list($span) = dd_trace_serialize_closed_spans();
unset($span['meta']['system.pid']);
var_dump($span['meta']);
?>
--EXPECT--
array(2) {
  ["answer_to_universe"]=>
  string(2) "42"
  ["null_value"]=>
  string(6) "(null)"
}
