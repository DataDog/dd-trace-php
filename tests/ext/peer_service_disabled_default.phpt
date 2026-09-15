--TEST--
Assesses the opt-in behavior of peer service
--ENV--
DD_TRACE_PEER_SERVICE_MAPPING=foo:bar
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';

function foo() { }

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    $span->peerServiceSources = ['db.instance', 'net.peer.name'];
    $span->meta['db.instance'] = 'db1';
    $span->meta['net.peer.name'] = 'xyz';
    $span->meta['foo'] = 'bar';
});

foo();

var_dump(dd_clean_spans());

?>
--EXPECTF--
array(1) {
  [0]=>
  array(12) {
    ["trace_id"]=>
    string(%d) "%d"
    ["trace_id_high"]=>
    string(16) "%s"
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
    ["span_kind"]=>
    int(1)
    ["attributes"]=>
    array(3) {
      ["db.instance"]=>
      string(3) "db1"
      ["net.peer.name"]=>
      string(3) "xyz"
      ["foo"]=>
      string(3) "bar"
    }
  }
}
