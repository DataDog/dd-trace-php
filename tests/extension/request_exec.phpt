--TEST--
Test request execution.
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.log_level=trace
datadog.appsec.log_file=/tmp/php_appsec_test.log

--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,request_exec,request_exec_add_data};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init(['ok', []])),
    response_list(response_request_exec(['ok', []])),
    response_list(response_request_shutdown(['ok', [], new ArrayObject(), new ArrayObject()]))
]);

rinit();

var_dump(request_exec([
    'key 01' => 'some value',
    'key 02' => 123,
    'key 03' => ['some' => 'array']
]));

var_dump(request_exec('value'));
var_dump(request_exec(55));


rshutdown();

$commands = $helper->get_commands();

var_dump($commands[2]);

?>
--EXPECTF--
bool(true)
bool(false)
bool(false)
array(2) {
  [0]=>
  string(12) "request_exec"
  [1]=>
  array(1) {
    [0]=>
    array(3) {
      ["key 01"]=>
      string(10) "some value"
      ["key 02"]=>
      int(123)
      ["key 03"]=>
      array(1) {
        ["some"]=>
        string(5) "array"
      }
    }
  }
}
