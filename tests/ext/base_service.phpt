--TEST--
Properly set _dd.base_service when service name is manually changed
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';

function foo() { }

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    $span->service = 'changed';
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
    string(7) "changed"
    ["type"]=>
    string(3) "cli"
    ["span_kind"]=>
    int(1)
    ["attributes"]=>
    array(2) {
      ["_dd.svc_src"]=>
      string(1) "m"
      ["_dd.base_service"]=>
      string(16) "base_service.php"
    }
  }
}
