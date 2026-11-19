--TEST--
The same attributes serialize identically on a span, a span link and a span event
--DESCRIPTION--
null is skipped (in lists the element is omitted), an empty array is an empty list (an empty object
an empty map), and empty or numeric keys are kept, at the top level and nested.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
$attrs = [
    'null' => null,
    'empty' => [],
    'object' => new stdClass,
    '' => 'empty key',
    7 => 'numeric key',
    'list' => ['a', null, [], ['n' => null, 'e' => []]],
    'map' => ['n' => null, 'e' => [], '' => 1, 3 => 'three', 'l' => [null]],
];

$s = \DDTrace\start_span();
$s->attributes = $attrs;
$link = new \DDTrace\SpanLink();
$link->traceId = "42";
$link->spanId = "6";
$link->attributes = $attrs;
$s->links[] = $link;
$s->events[] = new \DDTrace\SpanEvent('evt', $attrs, 1);
\DDTrace\close_span();

$span = dd_trace_serialize_closed_spans()['chunks'][0]['spans'][0];
// The span also carries tracer attributes (process_id, ...): compare only the ones set here.
$spanAttrs = array_intersect_key($span['attributes'], $attrs);
$linkAttrs = $span['span_links'][0]['attributes'];
$eventAttrs = $span['span_events'][0]['attributes'];
var_dump($spanAttrs === $linkAttrs, $linkAttrs === $eventAttrs);
var_dump($spanAttrs);
?>
--EXPECT--
bool(true)
bool(true)
array(6) {
  ["empty"]=>
  array(0) {
  }
  ["object"]=>
  array(0) {
  }
  [""]=>
  string(9) "empty key"
  [7]=>
  string(11) "numeric key"
  ["list"]=>
  array(3) {
    [0]=>
    string(1) "a"
    [1]=>
    array(0) {
    }
    [2]=>
    array(1) {
      ["e"]=>
      array(0) {
      }
    }
  }
  ["map"]=>
  array(4) {
    ["e"]=>
    array(0) {
    }
    [""]=>
    int(1)
    [3]=>
    string(5) "three"
    ["l"]=>
    array(0) {
    }
  }
}
