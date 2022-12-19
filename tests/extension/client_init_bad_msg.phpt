--TEST--
client_init returns a response with an incorrect format
--INI--
datadog.appsec.log_level=trace
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit,backoff_status,is_without_holes};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/logging.php';

$helper = Helper::createRun([
    response_list(response_client_init(['msg' => ['y' => 'ok']]))
]);

var_dump(rinit());

match_log('/Unexpected client_init response: mpack_error_type/');
match_log('/Response message for client_init does not have the expected form/');
match_log('/Initial exchange with helper failed; abandoning the connection/');

var_dump(backoff_status());

match_log('/Contents of message \\(base64 encoded\\) \\(part 1\\): /');
match_log('/Contents of message \\(base64 encoded\\): /');

?>
--EXPECTF--
bool(true)
found message in log matching /Unexpected client_init response: mpack_error_type/
found message in log matching /Response message for client_init does not have the expected form/
found message in log matching /Initial exchange with helper failed; abandoning the connection/
array(2) {
  ["failed_count"]=>
  int(1)
  ["next_retry"]=>
  float(%f)
}
found message in log matching /Contents of message \(base64 encoded\) \(part 1\): /
found message in log matching /Contents of message \(base64 encoded\): /
