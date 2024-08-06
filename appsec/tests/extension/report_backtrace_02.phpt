--TEST--
DD_APPSEC_MAX_STACK_TRACES by default is 2 so only two backtraces are reported
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
extension=ddtrace.so
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

use function datadog\appsec\testing\{report_exploit_backtrace, root_span_get_meta_struct};

function two($param01, $param02)
{
    report_exploit_backtrace($param01);
}

function one($param01)
{
    two($param01, "other");
}

DDTrace\start_span();
$root = DDTrace\active_span();


one("foo01"); //Line 22
one("foo02"); //Line 23
one("foo03"); //Line 24. This one is not generated as default DD_APPSEC_MAX_STACK_TRACES is 2

var_dump(root_span_get_meta_struct());

?>
--EXPECTF--
array(1) {
  ["_dd.stack"]=>
  array(1) {
    ["exploit"]=>
    array(2) {
      [0]=>
      array(3) {
        ["language"]=>
        string(3) "php"
        ["id"]=>
        string(5) "foo01"
        ["frames"]=>
        array(2) {
          [0]=>
          array(4) {
            ["line"]=>
            int(13)
            ["function"]=>
            string(3) "two"
            ["file"]=>
            string(23) "report_backtrace_02.php"
            ["id"]=>
            int(0)
          }
          [1]=>
          array(4) {
            ["line"]=>
            int(20)
            ["function"]=>
            string(3) "one"
            ["file"]=>
            string(23) "report_backtrace_02.php"
            ["id"]=>
            int(1)
          }
        }
      }
      [1]=>
      array(3) {
        ["language"]=>
        string(3) "php"
        ["id"]=>
        string(5) "foo02"
        ["frames"]=>
        array(2) {
          [0]=>
          array(4) {
            ["line"]=>
            int(13)
            ["function"]=>
            string(3) "two"
            ["file"]=>
            string(23) "report_backtrace_02.php"
            ["id"]=>
            int(0)
          }
          [1]=>
          array(4) {
            ["line"]=>
            int(21)
            ["function"]=>
            string(3) "one"
            ["file"]=>
            string(23) "report_backtrace_02.php"
            ["id"]=>
            int(1)
          }
        }
      }
    }
  }
}
