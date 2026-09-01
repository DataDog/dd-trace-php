--TEST--
Use DDTrace\close_span() on span started within internal span
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';

function test() { }

DDTrace\trace_function("test", function($s) {
    $span = DDTrace\start_span();
    $span->name = "my precious span";
});

test();

var_dump(dd_clean_spans());

// has no effect
DDTrace\close_span();

var_dump(dd_clean_spans());

?>
--EXPECTF--
[ddtrace] [warning] [%d] Found unfinished span while automatically closing spans with name 'my precious span'
array(1) {
  [0]=>
  array(13) {
    ["trace_id"]=>
    string(%d) "%d"
    ["trace_id_high"]=>
    string(16) "%s"
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
    ["span_kind"]=>
    int(1)
    ["sampling_priority"]=>
    int(1)
    ["sampling_mechanism"]=>
    int(0)
    ["attributes"]=>
    array(6) {
      ["runtime-id"]=>
      string(36) "%s"
      ["process_id"]=>
      float(%f)
      ["_dd.agent_psr"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%f)
      ["php.memory.peak_usage_bytes"]=>
      float(%f)
      ["php.memory.peak_real_usage_bytes"]=>
      float(%f)
    }
  }
}
[ddtrace] [error] [%d] There is no user-span on the top of the stack. Cannot close.
array(0) {
}
[ddtrace] [info] [%d] No finished traces to be sent to the agent
