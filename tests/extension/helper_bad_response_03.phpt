--TEST--
Invalid response format
--INI--
datadog.appsec.log_level=trace
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/logging.php';

$helper = Helper::createInitedRun([
    response_list('this should be a array instead')
]);

var_dump(rinit());

match_log('/Invalid response. Expected array but got mpack_type_str/');


?>
--EXPECTF--
Warning: datadog\appsec\testing\rinit(): [ddappsec] Invalid response. Expected array but got mpack_type_str in %shelper_bad_response_03.php on line %d
bool(true)
found message in log matching /Invalid response. Expected array but got mpack_type_str/
