--TEST--
Push params ara sent on request_exec
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.waf_timeout=42
datadog.appsec.log_level=debug
datadog.appsec.enabled=1
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
REQUEST_URI=/static01/dynamic01/static02/dynamic02
URL_SCHEME=http
HTTP_CONTENT_TYPE=text/plain
HTTP_CONTENT_LENGTH=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
use function datadog\appsec\push_params;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init(['ok', []])),
    response_list(response_request_exec(['ok', [], [], [], [], false]))
]);

var_dump(rinit());
push_params(["some" => "params", "more" => "parameters"]);
var_dump(rshutdown());

var_dump($helper->get_command("request_exec"));

?>
--EXPECTF--
bool(true)
bool(true)
array(2) {
  [0]=>
  string(12) "request_exec"
  [1]=>
  array(1) {
    [0]=>
    array(1) {
      ["server.request.path_params"]=>
      array(2) {
        ["some"]=>
        string(6) "params"
        ["more"]=>
        string(10) "parameters"
      }
    }
  }
}