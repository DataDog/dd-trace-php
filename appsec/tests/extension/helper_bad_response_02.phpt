--TEST--
Send invalid type
--INI--
datadog.appsec.log_level=trace
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/logging.php';

$helper = Helper::createInitedRun([
    response_list(response(['this should be a plain text'], ['msg' => ['y' => 'ok']]))
]);

var_dump(rinit());

match_log('/Unexpected type field. Expected string but got mpack_type_array/');


?>
--EXPECTF--
Warning: datadog\appsec\testing\rinit(): [ddappsec] Unexpected type field. Expected string but got mpack_type_array in %shelper_bad_response_02.php on line %d
bool(true)
found message in log matching /Unexpected type field. Expected string but got mpack_type_array/
