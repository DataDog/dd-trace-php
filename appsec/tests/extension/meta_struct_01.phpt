--TEST--
Meta struct value gets serialized on span
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

use function datadog\appsec\testing\root_span_add_meta_struct;

DDTrace\start_span();

root_span_add_meta_struct("foo", "bar");
root_span_add_meta_struct("john", "doe");

DDTrace\close_span(0);
$span = dd_trace_serialize_closed_spans();
var_dump($span[0]["meta_struct"]);

?>
--EXPECTF--
array(2) {
  ["foo"]=>
  string(3) "bar"
  ["john"]=>
  string(3) "doe"
}
