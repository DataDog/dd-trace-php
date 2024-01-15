--TEST--
Use DDTrace\close_span() on span started within internal span
--ENV--
DD_TRACE_DEBUG=1
DD_AUTOFINISH_SPANS=1
--FILE--
<?php

function test() { }

DDTrace\trace_function("test", function($s) {
    $span = DDTrace\start_span();
    $span->name = "my precious span";
});

test();

var_dump(dd_trace_serialize_closed_spans());

// has no effect
DDTrace\close_span();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
[ddtrace] [warning] Found unfinished span while automatically closing spans with name 'my precious span'
array(2) {
  [0]=>
  array(9) {
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
    string(4) "test"
    ["resource"]=>
    string(4) "test"
    ["service"]=>
    string(41) "start_span_without_closing_autofinish.php"
    ["type"]=>
    string(3) "cli"
  }
  [1]=>
  array(9) {
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
    string(16) "my precious span"
    ["resource"]=>
    string(16) "my precious span"
    ["service"]=>
    string(41) "start_span_without_closing_autofinish.php"
    ["type"]=>
    string(3) "cli"
  }
}
[ddtrace] [error] There is no user-span on the top of the stack. Cannot close.
array(0) {
}
[ddtrace] [info] No finished traces to be sent to the agent
