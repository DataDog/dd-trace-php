--TEST--
Test all happy path scenarios of enabling/disabling by RC
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown};

include __DIR__ . '/inc/mock_helper.php';
$helper = Helper::createInitedRun([
    response_list(response_config_features(true)), //First rinit enables
    response_list(response_request_init([[['ok', []]]])), //Since it got enabled, it should call to request init
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])), //End of request
    response_list(response_config_features(false)), //Second call at rinit disabled it
    response_list(response_config_sync()), //Third call which does not change anything
]);

//Enabled not configured, therefore it is not enabled
var_dump(\datadog\appsec\is_enabled());
rinit(); //On this rinit it gets enabled
var_dump(\datadog\appsec\is_enabled());
rshutdown();
var_dump(\datadog\appsec\is_enabled());
rinit(); //Second rinit. This time it got disabled
var_dump(\datadog\appsec\is_enabled());
rshutdown();
var_dump(\datadog\appsec\is_enabled());
rinit(); //Third rinit. Nothing changes
var_dump(\datadog\appsec\is_enabled());
rshutdown();
var_dump(\datadog\appsec\is_enabled())
?>
--EXPECTF--
bool(false)
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
bool(false)
