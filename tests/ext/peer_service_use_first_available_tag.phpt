--TEST--
Use the first available tag from peerServiceSources to set peer.service
--ENV--
DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true
--FILE--
<?php

function foo() { }
function bar() { }
function baz() { }

DDTrace\trace_function("foo", function (\DDTrace\SpanData $span) {
    $span->meta['db.instance'] = 'db1';
    $span->peerServiceSources = ['db.instance', 'net.peer.name'];
});

DDTrace\trace_function("bar", function (\DDTrace\SpanData $span) {
    $span->meta['db.instance'] = 'db1';
    $span->meta['net.peer.name'] = 'db1.example.com';
    $span->peerServiceSources = ['db.instance', 'net.peer.name'];
});

DDTrace\trace_function("baz", function (\DDTrace\SpanData $span) {
    $span->meta['net.peer.name'] = 'db1.example.com';
    $span->peerServiceSources = ['db.instance', 'net.peer.name'];
});

foo(); // db.instance is set, so peer.service is set to db1
bar(); // db.instance is set, so peer.service is set to db1
baz(); // db.instance is not set, and net.peer.name is set, so peer.service is set to db1.example.com

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
array(3) {
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
    string(3) "baz"
    ["resource"]=>
    string(3) "baz"
    ["service"]=>
    string(40) "peer_service_use_first_available_tag.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(3) {
      ["_dd.peer.service.source"]=>
      string(13) "net.peer.name"
      ["net.peer.name"]=>
      string(15) "db1.example.com"
      ["peer.service"]=>
      string(15) "db1.example.com"
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
    string(3) "bar"
    ["resource"]=>
    string(3) "bar"
    ["service"]=>
    string(40) "peer_service_use_first_available_tag.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(4) {
      ["_dd.peer.service.source"]=>
      string(11) "db.instance"
      ["db.instance"]=>
      string(3) "db1"
      ["net.peer.name"]=>
      string(15) "db1.example.com"
      ["peer.service"]=>
      string(3) "db1"
    }
  }
  [2]=>
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
    string(40) "peer_service_use_first_available_tag.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(3) {
      ["_dd.peer.service.source"]=>
      string(11) "db.instance"
      ["db.instance"]=>
      string(3) "db1"
      ["peer.service"]=>
      string(3) "db1"
    }
  }
}