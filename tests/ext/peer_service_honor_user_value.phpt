--TEST--
If peer.service is already set by the user, honor it
--ENV--
DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true
--FILE--
<?php

function foo() { }
function bar() { }


DDTrace\trace_function("foo", function (\DDTrace\SpanData $span) {
    $span->meta['db.instance'] = 'db1';
    $span->meta['peer.service'] = 'xyz';
});

DDTrace\trace_function("bar", function (\DDTrace\SpanData $span) {
    $span->meta['db.instance'] = 'db1';
    $span->meta['peer.service'] = 'xyz';
    $span->peerServiceSources = ['db.instance', 'net.peer.name'];
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
    string(33) "peer_service_honor_user_value.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(3) {
      ["_dd.peer.service.source"]=>
      string(12) "peer.service"
      ["db.instance"]=>
      string(3) "db1"
      ["peer.service"]=>
      string(3) "xyz"
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
    string(33) "peer_service_honor_user_value.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(3) {
      ["_dd.peer.service.source"]=>
      string(12) "peer.service"
      ["db.instance"]=>
      string(3) "db1"
      ["peer.service"]=>
      string(3) "xyz"
    }
  }
}