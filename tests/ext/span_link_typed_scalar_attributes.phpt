--TEST--
Span link scalar attributes keep their PHP type when serialized, and null is skipped
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';

use DDTrace\SpanLink;

function foo() {}

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    $span->name = 'foo';

    $link = new SpanLink();
    $link->traceId = "42";
    $link->spanId = "6";
    $link->attributes = ['t' => true, 'f' => false, 'i' => 42, 'd' => 1.5, 's' => 'str', 'n' => null];
    $span->links[] = $link;
});

foo();

$spans = dd_clean_spans();
var_dump($spans[0]['span_links'][0]['attributes']);
?>
--EXPECT--
array(5) {
  ["t"]=>
  bool(true)
  ["f"]=>
  bool(false)
  ["i"]=>
  int(42)
  ["d"]=>
  float(1.5)
  ["s"]=>
  string(3) "str"
}
