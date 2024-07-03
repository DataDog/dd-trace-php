--TEST--
Test calls to request_shutdown only when enabled
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown};

include __DIR__ . '/inc/mock_helper.php';
$helper = Helper::createInitedRun([
    //Firt request
    response_list(response_config_sync()),
    //Second request
    response_list(response_config_features(true)), //Config sync enables
    response_list(response_request_init([[['ok', []]]])), //Since it got enabled, it should call to request init
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])), //End of request
    //Third request
    response_list(response_config_features(false)), //Config sync enables
    //Four request
    response_list(response_config_sync()),
]);

var_dump(\datadog\appsec\is_enabled());
rinit();
rshutdown();
var_dump(\datadog\appsec\is_enabled());
rinit();
rshutdown();
var_dump(\datadog\appsec\is_enabled());
rinit();
rshutdown();
var_dump(\datadog\appsec\is_enabled());
rinit();
rshutdown();
var_dump(\datadog\appsec\is_enabled());
?>
--EXPECTF--
bool(false)
bool(false)
bool(true)
bool(false)
bool(false)
