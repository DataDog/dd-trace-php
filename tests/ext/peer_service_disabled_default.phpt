--TEST--
Assesses the opt-in behavior of peer service
--ENV--
DD_TRACE_PEER_SERVICE_MAPPING=foo:bar
--FILE--
<?php

function foo() { }

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    $span->peerServiceSources = ['db.instance', 'net.peer.name'];
    $span->meta['db.instance'] = 'db1';
    $span->meta['net.peer.name'] = 'xyz';
    $span->meta['foo'] = 'bar';
});

foo();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
array(1) {
  [0]=>
  array(10) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["parent_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(3) "foo"
    ["resource"]=>
    string(3) "foo"
    ["service"]=>
    string(33) "peer_service_disabled_default.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(3) {
      ["db.instance"]=>
      string(3) "db1"
      ["foo"]=>
      string(3) "bar"
      ["net.peer.name"]=>
      string(3) "xyz"
    }
  }
}