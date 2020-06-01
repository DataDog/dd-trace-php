--TEST--
Keep spans in limited mode (internal functions)
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--ENV--
DD_TRACE_SPANS_LIMIT=5
--INI--
ddtrace.traced_internal_functions=array_sum,mt_rand
--FILE--
<?php
dd_trace_function('array_sum', function (\DDTrace\SpanData $span) {
    $span->name = 'array_sum';
});
dd_trace_function('mt_rand', [
    'instrument_when_limited' => 1,
    'posthook' => function (\DDTrace\SpanData $span) {
        $span->name = 'mt_rand';
    }
]);

var_dump(dd_trace_tracer_is_limited());
mt_rand();
for ($i = 0; $i < 100; $i++) {
    array_sum([]);
}
var_dump(dd_trace_tracer_is_limited());
mt_rand();
mt_rand();

array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(false)
bool(true)
mt_rand
mt_rand
array_sum
array_sum
array_sum
array_sum
mt_rand
