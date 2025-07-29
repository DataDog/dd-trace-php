--TEST--
Properly remap peer service's sources based on DD_TRACE_PEER_SERVICE_MAPPING
--ENV--
DD_TRACE_PEER_SERVICE_MAPPING=foo:bar,db.instance:db,xyz:abc,db1:database,net.peer.name:net
DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true
--FILE--
<?php

function foo() { }
function bar() { }

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    $span->meta['db.instance'] = 'db1';
    $span->meta['net.peer.name'] = 'xyz';
    $span->meta['foo'] = 'bar';
    $span->peerServiceSources = ['db.instance', 'net.peer.name'];
});

DDTrace\trace_function('bar', function (\DDTrace\SpanData $span) {
    $span->meta['db.instance'] = 'db1';
    $span->meta['net.peer.name'] = 'xyz';
    $span->meta['foo'] = 'bar';
    $span->peerServiceSources = ['db.instance', 'net.peer.name'];
    $span->meta['peer.service'] = 'net.peer.name';
});

foo();
bar();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
array(2) {
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
    string(3) "bar"
    ["resource"]=>
    string(3) "bar"
    ["service"]=>
    string(26) "peer_service_remapping.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(6) {
      ["_dd.peer.service.source"]=>
      string(12) "peer.service"
      ["db.instance"]=>
      string(3) "db1"
      ["foo"]=>
      string(3) "bar"
      ["net.peer.name"]=>
      string(3) "xyz"
      ["peer.service"]=>
      string(3) "net"
      ["peer.service.remapped_from"]=>
      string(13) "net.peer.name"
    }
  }
  [1]=>
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
    string(26) "peer_service_remapping.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(6) {
      ["_dd.peer.service.source"]=>
      string(11) "db.instance"
      ["db.instance"]=>
      string(3) "db1"
      ["foo"]=>
      string(3) "bar"
      ["net.peer.name"]=>
      string(3) "xyz"
      ["peer.service"]=>
      string(8) "database"
      ["peer.service.remapped_from"]=>
      string(3) "db1"
    }
  }
}