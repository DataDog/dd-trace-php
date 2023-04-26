--TEST--
Serialization of a span with multiple span links
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
--FILE--
<?php

function foo() {}
function bar() {}
function baz() {}


DDTrace\trace_function('foo',
    function (\DDTrace\SpanData $span) use (&$firstLink) {
        $span->name = 'foo';

        $firstLink = $span->getLink();
    }
);

DDTrace\trace_function('bar',
    function (\DDTrace\SpanData $span) use (&$secondLink) {
        $span->name = 'bar';

        $secondLink = $span->getLink();
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

var_dump(json_encode($firstLink));
var_dump($firstLink->jsonSerialize());
var_dump(json_encode($secondLink));
var_dump($secondLink->jsonSerialize());
var_dump(dd_trace_serialize_closed_spans()[0]);

?>
--EXPECTF--
string(76) "{"trace_id":"0000000000000000c151df7d6ee5e2d6","span_id":"a3978fb9b92502a8"}"
array(5) {
  ["trace_id"]=>
  string(32) "0000000000000000c151df7d6ee5e2d6"
  ["span_id"]=>
  string(16) "a3978fb9b92502a8"
}
string(76) "{"trace_id":"0000000000000000c151df7d6ee5e2d6","span_id":"c08c967f0e5e7b0a"}"
array(5) {
  ["trace_id"]=>
  string(32) "0000000000000000c151df7d6ee5e2d6"
  ["span_id"]=>
  string(16) "c08c967f0e5e7b0a"
}
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
    string(155) "[{"trace_id":"0000000000000000c151df7d6ee5e2d6","span_id":"a3978fb9b92502a8"},{"trace_id":"0000000000000000c151df7d6ee5e2d6","span_id":"c08c967f0e5e7b0a"}]"
  }
}
