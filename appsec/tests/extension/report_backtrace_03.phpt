--TEST--
DD_APPSEC_MAX_STACK_TRACES can be configured
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_APPSEC_MAX_STACK_TRACES=3
--INI--
extension=ddtrace.so
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

use function datadog\appsec\testing\{report_backtrace, root_span_get_meta_struct};

function two($param01, $param02)
{
    report_backtrace();
}

function one($param01)
{
    two($param01, "other");
}

DDTrace\start_span();
$root = DDTrace\active_span();


one("foo"); //Line 22
one("foo"); //Line 23
one("foo"); //Line 24

var_dump(root_span_get_meta_struct());

?>
--EXPECTF--
array(1) {
  ["_dd.stack"]=>
  array(1) {
    ["exploit"]=>
    array(3) {
      [0]=>
      array(2) {
        ["language"]=>
        string(3) "php"
        ["frames"]=>
        array(2) {
          [0]=>
          array(4) {
            ["line"]=>
            int(15)
            ["function"]=>
            string(3) "two"
            ["file"]=>
            string(23) "report_backtrace_03.php"
            ["id"]=>
            int(0)
          }
          [1]=>
          array(4) {
            ["line"]=>
            int(22)
            ["function"]=>
            string(3) "one"
            ["file"]=>
            string(23) "report_backtrace_03.php"
            ["id"]=>
            int(1)
          }
        }
      }
      [1]=>
      array(2) {
        ["language"]=>
        string(3) "php"
        ["frames"]=>
        array(2) {
          [0]=>
          array(4) {
            ["line"]=>
            int(15)
            ["function"]=>
            string(3) "two"
            ["file"]=>
            string(23) "report_backtrace_03.php"
            ["id"]=>
            int(0)
          }
          [1]=>
          array(4) {
            ["line"]=>
            int(23)
            ["function"]=>
            string(3) "one"
            ["file"]=>
            string(23) "report_backtrace_03.php"
            ["id"]=>
            int(1)
          }
        }
      }
      [2]=>
      array(2) {
        ["language"]=>
        string(3) "php"
        ["frames"]=>
        array(2) {
          [0]=>
          array(4) {
            ["line"]=>
            int(15)
            ["function"]=>
            string(3) "two"
            ["file"]=>
            string(23) "report_backtrace_03.php"
            ["id"]=>
            int(0)
          }
          [1]=>
          array(4) {
            ["line"]=>
            int(24)
            ["function"]=>
            string(3) "one"
            ["file"]=>
            string(23) "report_backtrace_03.php"
            ["id"]=>
            int(1)
          }
        }
      }
    }
  }
}