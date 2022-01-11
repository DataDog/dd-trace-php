--TEST--
Use DDTrace\close_span() on span started within internal span
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_GENERATE_ROOT_SPAN=0
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
array(1) {
  [0]=>
  array(10) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
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
    string(30) "start_span_without_closing.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(2) {
      ["system.pid"]=>
      string(%d) "%d"
      ["_dd.p.upstream_services"]=>
      string(50) "c3RhcnRfc3Bhbl93aXRob3V0X2Nsb3NpbmcucGhw|1|1|1.000"
    }
    ["metrics"]=>
    array(3) {
      ["_dd.rule_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%f)
    }
  }
}
There is no user-span on the top of the stack. Cannot close.
array(0) {
}
No finished traces to be sent to the agent
