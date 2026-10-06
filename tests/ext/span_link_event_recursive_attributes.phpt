--TEST--
Self-referencing span link, event and meta attributes serialize with a bounded "" placeholder
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';

use DDTrace\SpanEvent;
use DDTrace\SpanLink;

function foo() {}

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    $span->name = 'foo';

    $arr = ['x'];
    $arr[] = &$arr;
    $obj = new stdClass;
    $obj->v = 1;
    $obj->self = $obj;
    $ao = new ArrayObject(['d' => 2]);
    $ao['self'] = $ao;
    // DateTime builds a fresh properties table on every read, so only an object-level guard stops it.
    $dt = new DateTime('2020-01-01 00:00:00 UTC');
    @$dt->self = $dt;
    $attrs = ['arr' => $arr, 'obj' => $obj, 'ao' => $ao, 'empty' => []];

    $link = new SpanLink();
    $link->traceId = "42";
    $link->spanId = "6";
    $link->attributes = $attrs + ['dt' => $dt];
    $span->links[] = $link;
    $span->events[] = new SpanEvent('evt', $attrs + ['dt' => $dt], 1);
    $span->meta['dt'] = $dt;
});

foo();

$spans = dd_trace_serialize_closed_spans();
foreach ([$spans[0]['span_links'][0]['attributes'], $spans[0]['span_events'][0]['attributes']] as $attrs) {
    $dt = $attrs['dt'];
    unset($attrs['dt']);
    echo json_encode($attrs), "\n";
    var_dump($dt['self'], $dt['timezone']);
}
var_dump($spans[0]['attributes']['dt']['self']);

?>
--EXPECT--
{"arr":["x",""],"obj":{"v":1,"self":""},"ao":{"d":2,"self":""},"empty":[]}
string(0) ""
string(3) "UTC"
{"arr":["x",""],"obj":{"v":1,"self":""},"ao":{"d":2,"self":""},"empty":[]}
string(0) ""
string(3) "UTC"
string(0) ""
