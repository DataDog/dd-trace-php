--TEST--
A response which is a plain string is a failure
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/logging.php';

$helper = Helper::createRun([
    response_list('this should be a array instead')
]);

var_dump(rinit());

match_log('/The AppSec helper failed request_init: mock response without type/');
?>
--EXPECTF--
bool(true)
found message in log matching /The AppSec helper failed request_init: mock response without type/
