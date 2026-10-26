--TEST--
Test root_span_add_attribute
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_SERVICE=appsec_tests
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');
// Fail if root span not available
var_dump(\datadog\appsec\testing\root_span_add_attribute("before", "root_span"));
DDTrace\start_span();
var_dump(\datadog\appsec\testing\root_span_add_attribute("after", "root_span"));
// Fail if we attempt to add an existing tag
var_dump(\datadog\appsec\testing\root_span_add_attribute("after", "duplicate"));
DDTrace\close_span(0);
var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECTF--
bool(false)
bool(true)
bool(false)
array(2) {
  ["attributes"]=>
  array(1) {
    ["_dd.sdk.otlp_export"]=>
    string(5) "false"
  }
  ["chunks"]=>
  array(1) {
    [0]=>
    array(4) {
      ["trace_id"]=>
      string(%d) "%s"
      ["priority"]=>
      int(1)
      ["sampling_mechanism"]=>
      int(0)
      ["spans"]=>
      array(1) {
        [0]=>
        array(8) {
          ["span_id"]=>
          string(%d) "%d"
          ["start"]=>
          int(%d)
          ["duration"]=>
          int(%d)
          ["name"]=>
          string(27) "root_span_add_attribute.php"
          ["resource"]=>
          string(27) "root_span_add_attribute.php"
          ["service"]=>
          string(12) "appsec_tests"
          ["type"]=>
          string(3) "cli"
          ["attributes"]=>
          array(%d) {%A
            ["after"]=>
            string(9) "root_span"%A
          }
        }
      }
    }
  }
}
