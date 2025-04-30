--TEST--
Using a wrongly formatted DD_TRACE_PEER_SERVICE_MAPPING doesn't break the tracer
--ENV--
DD_TRACE_PEER_SERVICE_MAPPING=foo=bar,only_tag
DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true
--FILE--
<?php

function foo() { }
function bar() { }

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    $span->meta['db.instance'] = 'foo';
    $span->peerServiceSources = ['db.instance', 'net.peer.name'];
});

DDTrace\trace_function('bar', function (\DDTrace\SpanData $span) {
    $span->meta['db.instance'] = 'only_tag';
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
    string(29) "peer_service_wrong_values.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(3) {
      ["_dd.peer.service.source"]=>
      string(11) "db.instance"
      ["db.instance"]=>
      string(8) "only_tag"
      ["peer.service"]=>
      string(8) "only_tag"
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
    string(29) "peer_service_wrong_values.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(3) {
      ["_dd.peer.service.source"]=>
      string(11) "db.instance"
      ["db.instance"]=>
      string(3) "foo"
      ["peer.service"]=>
      string(3) "foo"
    }
  }
}