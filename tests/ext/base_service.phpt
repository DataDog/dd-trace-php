--TEST--
Properly set _dd.base_service when service name is manually changed
--FILE--
<?php

function foo() { }

DDTrace\trace_function('foo', function (\DDTrace\SpanData $span) {
    $span->service = 'changed';
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
    string(7) "changed"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(1) {
      ["_dd.base_service"]=>
      string(16) "base_service.php"
    }
  }
}
