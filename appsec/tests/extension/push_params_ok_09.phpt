--TEST--
SSRF Rule can be sent
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.rasp_enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
use function datadog\appsec\push_addresses;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['ok', []]], [], [], [], false])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

var_dump(rinit());
push_addresses(["server.request.path_params" => ["some" => "params", "more" => "parameters"]], "ssrf");
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
  array(2) {
    [0]=>
    string(4) "ssrf"
    [1]=>
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
