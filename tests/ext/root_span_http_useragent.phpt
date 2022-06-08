--TEST--
Verify the user agent is added to the root span on serialization.
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: http.useragent only available on php 7 and 8'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
HTTP_USER_AGENT=dd_trace_user_agent
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span(0);
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
    string(28) "root_span_http_useragent.php"
    ["resource"]=>
    string(28) "root_span_http_useragent.php"
    ["service"]=>
    string(28) "root_span_http_useragent.php"
    ["type"]=>
    string(3) "cli"
    ["meta"]=>
    array(4) {
      ["system.pid"]=>
      string(%d) "%d"
      ["http.useragent"]=>
      string(19) "dd_trace_user_agent"
      ["_dd.dm.service_hash"]=>
      string(%d) %s
      ["_dd.p.dm"]=>
      string(%d) %s
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
