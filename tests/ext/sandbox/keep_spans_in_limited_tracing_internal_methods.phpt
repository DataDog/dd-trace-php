--TEST--
Keep spans in limited mode (internal methods)
--ENV--
DD_TRACE_SPANS_LIMIT=5
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=DateTime::format,DateTime::setTime
--FILE--
<?php
date_default_timezone_set('UTC');

DDTrace\trace_method('DateTime', 'format', function (\DDTrace\SpanData $span) {
    $span->name = 'DateTime.format';
});
DDTrace\trace_method('DateTime', 'setTime', [
    'instrument_when_limited' => 1,
    'posthook' => function (\DDTrace\SpanData $span) {
        $span->name = 'DateTime.setTime';
    }
]);

var_dump(dd_trace_tracer_is_limited());
$dt = new DateTime('2019-12-20');
$dt->setTime(8, 10);
for ($i = 0; $i < 100; $i++) {
    $dt->format('r');
}
var_dump(dd_trace_tracer_is_limited());
$dt->setTime(8, 11);
$dt->setTime(8, 12);

array_map(function($span) {
    echo $span['name'] . PHP_EOL;
}, dd_trace_serialize_closed_spans());
?>
--EXPECT--
bool(false)
bool(true)
DateTime.setTime
DateTime.setTime
DateTime.format
DateTime.format
DateTime.format
DateTime.format
DateTime.setTime
