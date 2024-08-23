--TEST--
Report backtrace
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
extension=ddtrace.so
--FILE--
<?php
include __DIR__ . '/inc/ddtrace_version.php';

use function datadog\appsec\testing\{report_exploit_backtrace, decode_msgpack};

function two($param01, $param02)
{
    var_dump(report_exploit_backtrace("some id"));
}

function one($param01)
{
    two($param01, "other");
}

DDTrace\start_span();
$root = DDTrace\active_span();
one("foo");
DDTrace\close_span(0);
$span = dd_trace_serialize_closed_spans();
$meta_struct = $span[0]["meta_struct"];
var_dump(decode_msgpack($meta_struct["_dd.stack"]));
DDTrace\flush();
?>
--EXPECTF--
bool(true)
array(1) {
  ["exploit"]=>
  array(1) {
    [0]=>
    array(3) {
      ["language"]=>
      string(3) "php"
      ["id"]=>
      string(7) "some id"
      ["frames"]=>
      array(2) {
        [0]=>
        array(4) {
          ["line"]=>
          int(13)
          ["function"]=>
          string(3) "two"
          ["file"]=>
          string(23) "report_backtrace_01.php"
          ["id"]=>
          int(0)
        }
        [1]=>
        array(4) {
          ["line"]=>
          int(18)
          ["function"]=>
          string(3) "one"
          ["file"]=>
          string(23) "report_backtrace_01.php"
          ["id"]=>
          int(1)
        }
      }
    }
  }
}