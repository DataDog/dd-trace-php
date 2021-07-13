--TEST--
Transmit distributed header information to spans
--ENV--
HTTP_X_DATADOG_TRACE_ID=foo
HTTP_X_DATADOG_PARENT_ID=bar
HTTP_X_DATADOG_ORIGIN=datadog
DD_TRACE_GENERATE_ROOT_SPAN=0
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: Test requires internal distributed tracing handling'); ?>
--FILE--
<?php

$span = DDTrace\start_span();
$span->name = 'span';
DDTrace\close_span();

var_dump(dd_trace_serialize_closed_spans());

?>
--EXPECTF--
array(1) {
  [0]=>
  array(8) {
    ["trace_id"]=>
    string(%d) "%d"
    ["span_id"]=>
    string(%d) "%d"
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(4) "span"
    ["resource"]=>
    string(4) "span"
    ["meta"]=>
    array(2) {
      ["system.pid"]=>
      string(%d) "%d"
      ["_dd.origin"]=>
      string(7) "datadog"
    }
    ["metrics"]=>
    array(1) {
      ["php.compilation.total_time_ms"]=>
      float(%f)
    }
  }
}
