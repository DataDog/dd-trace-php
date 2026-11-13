--TEST--
Span link and event fields assigned by reference are serialized like plain values
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';

use DDTrace\ExceptionSpanEvent;
use DDTrace\SpanEvent;
use DDTrace\SpanLink;

function foo() {}

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    $span->name = 'foo';

    $traceId = '0123456789abcdef0123456789abcdef';
    $spanId = '0123456789abcdef';
    $traceState = 'dd=s:1';
    $link = new SpanLink();
    $link->traceId = &$traceId;
    $link->spanId = &$spanId;
    $link->traceState = &$traceState;
    $span->links[] = $link;

    $plainLink = new SpanLink();
    $plainLink->traceId = $traceId;
    $plainLink->spanId = $spanId;
    $plainLink->traceState = $traceState;
    $span->links[] = $plainLink;

    $name = 'evt';
    $event = new SpanEvent('placeholder', [], 1);
    $event->name = &$name;
    $span->events[] = $event;

    $exception = new \Exception('by reference');
    $exceptionEvent = new ExceptionSpanEvent(new \Exception('placeholder'));
    $exceptionEvent->exception = &$exception;
    $span->events[] = $exceptionEvent;
});

foo();

$spans = dd_clean_spans();
list($link, $plainLink) = $spans[0]['span_links'];
var_dump($link);
var_dump($link === $plainLink);
list($event, $exceptionEvent) = $spans[0]['span_events'];
var_dump($event['name']);
var_dump($exceptionEvent['attributes']['exception.message'] ?? null);

?>
--EXPECT--
array(3) {
  ["trace_id"]=>
  string(32) "0123456789abcdef0123456789abcdef"
  ["span_id"]=>
  string(16) "0123456789abcdef"
  ["tracestate"]=>
  string(6) "dd=s:1"
}
bool(true)
string(3) "evt"
string(12) "by reference"
