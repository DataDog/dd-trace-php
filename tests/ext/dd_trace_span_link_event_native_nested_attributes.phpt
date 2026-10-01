--TEST--
Span link and event array/object attributes serialize as native nested V1 attributes
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

    // A link whose attributes include an array and a nested object/map. Before Phase 3 these were
    // json_encode()'d to a string; they must now round-trip as native nested attributes.
    $link = new SpanLink();
    $link->traceId = "42";
    $link->spanId = "6";
    $link->attributes = ['nums' => [3, 4], 'nested' => ['k' => 'v']];
    $span->links[] = $link;

    // Same for an event.
    $span->events[] = new SpanEvent('evt', ['arr' => [3, 4], 'obj' => ['k' => 'v']], 1);
});

foo();

$spans = dd_clean_spans();
$link = $spans[0]['span_links'][0];
$event = $spans[0]['span_events'][0];

// Native nested shape: lists become packed arrays (typed leaves preserved), maps become assoc arrays.
var_dump($link['attributes']['nums']);
var_dump($link['attributes']['nested']);
var_dump($event['attributes']['arr']);
var_dump($event['attributes']['obj']);

?>
--EXPECT--
array(2) {
  [0]=>
  int(3)
  [1]=>
  int(4)
}
array(1) {
  ["k"]=>
  string(1) "v"
}
array(2) {
  [0]=>
  int(3)
  [1]=>
  int(4)
}
array(1) {
  ["k"]=>
  string(1) "v"
}
