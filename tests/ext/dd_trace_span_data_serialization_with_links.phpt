--TEST--
Serialization of a span with multiple span links
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';

function foo() {}
function bar() {}
function baz() {}


DDTrace\trace_function('foo',
    function (\DDTrace\SpanData $span) use (&$firstLink) {
        $span->name = 'foo';

        $firstLink = $span->getLink();
        // Drive the link through the real serialization path (produces meta["_dd.span_links"]).
        $span->links = [$firstLink];
    }
);

DDTrace\trace_function('bar',
    function (\DDTrace\SpanData $span) use (&$secondLink) {
        $span->name = 'bar';

        $secondLink = $span->getLink();
        // Drive the link through the real serialization path (produces meta["_dd.span_links"]).
        $span->links = [$secondLink];
    }
);

DDTrace\trace_function('baz',
    function (\DDTrace\SpanData $span) use (&$firstLink, &$secondLink) {
        $span->name = 'baz';

        $span->links = [$firstLink, $secondLink];
    }
);

foo();
bar();
baz();

$spans = dd_clean_spans();
// baz carries both links; foo and bar each carry their own self-link. All are asserted through
// the actual span serialization (meta["_dd.span_links"]), which is the real wire path.
var_dump($spans[0]);
var_dump($spans[1]['name'], $spans[1]['meta']['_dd.span_links']);
var_dump($spans[2]['name'], $spans[2]['meta']['_dd.span_links']);

?>
--EXPECTF--
array(10) {
  ["trace_id"]=>
  string(20) "13930160852258120406"
  ["span_id"]=>
  string(19) "2513787319205155662"
  ["parent_id"]=>
  string(20) "13930160852258120406"
  ["start"]=>
  int(%d)
  ["duration"]=>
  int(%d)
  ["name"]=>
  string(3) "baz"
  ["resource"]=>
  string(3) "baz"
  ["service"]=>
  string(47) "dd_trace_span_data_serialization_with_links.php"
  ["type"]=>
  string(3) "cli"
  ["meta"]=>
  array(1) {
    ["_dd.span_links"]=>
    string(155) "[{"trace_id":"%sc151df7d6ee5e2d6","span_id":"a3978fb9b92502a8"},{"trace_id":"%sc151df7d6ee5e2d6","span_id":"c08c967f0e5e7b0a"}]"
  }
}
string(3) "bar"
string(78) "[{"trace_id":"%sc151df7d6ee5e2d6","span_id":"c08c967f0e5e7b0a"}]"
string(3) "foo"
string(78) "[{"trace_id":"%sc151df7d6ee5e2d6","span_id":"a3978fb9b92502a8"}]"
