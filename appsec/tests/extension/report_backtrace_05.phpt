--TEST--
Trace are reported when helper indicates so
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
use function datadog\appsec\push_address;
use function datadog\appsec\testing\{decode_msgpack};
include __DIR__ . '/inc/ddtrace_version.php';
include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['stack_trace', ['stack_id' => '1234']]], []])),
]);

function two($param01, $param02)
{
    push_address("irrelevant", ["some" => "params", "more" => "parameters"]);
}

function one($param01)
{
    two($param01, "other");
}

rinit();

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
array(1) {
  ["exploit"]=>
  array(1) {
    [0]=>
    array(3) {
      ["language"]=>
      string(3) "php"
      ["id"]=>
      string(4) "1234"
      ["frames"]=>
      array(2) {
        [0]=>
        array(4) {
          ["line"]=>
          int(20)
          ["function"]=>
          string(3) "two"
          ["file"]=>
          string(23) "report_backtrace_05.php"
          ["id"]=>
          int(0)
        }
        [1]=>
        array(4) {
          ["line"]=>
          int(27)
          ["function"]=>
          string(3) "one"
          ["file"]=>
          string(23) "report_backtrace_05.php"
          ["id"]=>
          int(1)
        }
      }
    }
  }
}