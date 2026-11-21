--TEST--
Test calls to request_shutdown only when enabled
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--ENV--
DD_SERVICE=appsec_tests
DD_ENV=prod
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown, set_remote_asm_features};

include __DIR__ . '/inc/mock_helper.php';
$helper = Helper::createInitedRun([
    //Second request
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])), //End of request
]);

var_dump(\datadog\appsec\is_enabled());
//First request: not configured
rinit();
rshutdown();
var_dump(\datadog\appsec\is_enabled());
//Second request: enabled
set_remote_asm_features(true);
rinit();
rshutdown();
var_dump(\datadog\appsec\is_enabled());
//Third request: disabled
set_remote_asm_features(false);
rinit();
rshutdown();
var_dump(\datadog\appsec\is_enabled());
//Fourth request: still disabled
rinit();
rshutdown();
var_dump(\datadog\appsec\is_enabled());

echo implode(", ", array_column($helper->get_commands(), 0)), "\n";
?>
--EXPECTF--
bool(false)
bool(false)
bool(true)
bool(false)
bool(false)
request_init, request_shutdown
