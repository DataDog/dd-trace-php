--TEST--
Use DDTrace\close_span() on span started within internal span
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
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
[ddtrace] [warning] Found unfinished span while automatically closing spans with name 'my precious span'
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
    array(3) {
      ["_dd.p.dm"]=>
      string(2) "-0"
      ["_dd.p.tid"]=>
      string(16) "%s"
      ["runtime-id"]=>
      string(36) "%s"
    }
    ["metrics"]=>
    array(6) {
      ["_dd.agent_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%f)
      ["php.memory.peak_real_usage_bytes"]=>
      float(%f)
      ["php.memory.peak_usage_bytes"]=>
      float(%f)
      ["process_id"]=>
      float(%f)
    }
  }
}
[ddtrace] [error] There is no user-span on the top of the stack. Cannot close.
array(0) {
}
[ddtrace] [info] No finished traces to be sent to the agent
