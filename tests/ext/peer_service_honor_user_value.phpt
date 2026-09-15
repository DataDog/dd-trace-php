--TEST--
If peer.service is already set by the user, honor it
--ENV--
DD_TRACE_PEER_SERVICE_DEFAULTS_ENABLED=true
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';

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

var_dump(dd_clean_spans());
?>
--EXPECTF--
array(2) {
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
    string(3) "bar"
    ["resource"]=>
    string(3) "bar"
    ["service"]=>
    string(33) "peer_service_honor_user_value.php"
    ["type"]=>
    string(3) "cli"
    ["span_kind"]=>
    int(1)
    ["attributes"]=>
    array(3) {
      ["db.instance"]=>
      string(3) "db1"
      ["peer.service"]=>
      string(3) "xyz"
      ["_dd.peer.service.source"]=>
      string(12) "peer.service"
    }
  }
  [1]=>
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
    string(33) "peer_service_honor_user_value.php"
    ["type"]=>
    string(3) "cli"
    ["span_kind"]=>
    int(1)
    ["attributes"]=>
    array(3) {
      ["db.instance"]=>
      string(3) "db1"
      ["peer.service"]=>
      string(3) "xyz"
      ["_dd.peer.service.source"]=>
      string(12) "peer.service"
    }
  }
}
