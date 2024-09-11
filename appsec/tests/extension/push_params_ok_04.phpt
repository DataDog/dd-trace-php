--TEST--
Some addresses are rasp requests
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown, root_span_get_metrics};
use function datadog\appsec\push_address;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['ok', []]], [], [], [], false])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

var_dump(rinit());
$is_rasp = true;
push_address("server.request.path_params", 1234, $is_rasp);
var_dump(rshutdown());
print_r(root_span_get_metrics());

var_dump($helper->get_command("request_exec"));

?>
--EXPECTF--
bool(true)
bool(true)
Array
(
    [process_id] => %d
    [_dd.appsec.rasp.duration_ext] => %d
    [_dd.appsec.enabled] => %d
)
array(2) {
  [0]=>
  string(12) "request_exec"
  [1]=>
  array(2) {
    [0]=>
    bool(true)
    [1]=>
    array(1) {
      ["server.request.path_params"]=>
      int(1234)
    }
  }
}
