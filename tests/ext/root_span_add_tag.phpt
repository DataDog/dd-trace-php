--TEST--
Test ddtrace_root_span_add_tag
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: ddtrace_get_root_span only available on php 7 and 8'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
// Fail if root span not available
var_dump(\dd_trace_internal_fn("root_span_add_tag", "before", "root_span"));
DDTrace\start_span();
var_dump(\dd_trace_internal_fn("root_span_add_tag", "after", "root_span"));
// Fail if we attempt to add an existing tag
var_dump(\dd_trace_internal_fn("root_span_add_tag","after", "duplicate"));
DDTrace\close_span(0);
var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECTF--
bool(false)
bool(true)
bool(false)
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
    string(21) "root_span_add_tag.php"
    ["resource"]=>
    string(21) "root_span_add_tag.php"
    ["service"]=>
    string(21) "root_span_add_tag.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(2) {
      ["system.pid"]=>
      string(%d) "%d"
      ["after"]=>
      string(9) "root_span"
    }
    ["metrics"]=>
    array(3) {
      ["_dd.rule_psr"]=>
      float(1)
      ["_sampling_priority_v1"]=>
      float(1)
      ["php.compilation.total_time_ms"]=>
      float(%s)
    }
  }
}
