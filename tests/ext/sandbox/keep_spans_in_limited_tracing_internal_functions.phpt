--TEST--
Keep spans in limited mode (internal functions)
--ENV--
DD_TRACE_SPANS_LIMIT=5
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=array_sum,mt_rand
--FILE--
<?php
DDTrace\trace_function('array_sum', function (\DDTrace\SpanData $span) {
    $span->name = 'array_sum';
});
DDTrace\trace_function('mt_rand', [
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
