--TEST--
Test all happy path scenarios of enabling/disabling by RC
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
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
]);

//Enabled not configured, therefore it is not enabled
var_dump(\datadog\appsec\is_enabled());
set_remote_asm_features(true);
rinit(); //On this rinit it gets enabled
var_dump(\datadog\appsec\is_enabled());
rshutdown();
var_dump(\datadog\appsec\is_enabled());
set_remote_asm_features(false);
rinit(); //Second rinit. This time it got disabled
var_dump(\datadog\appsec\is_enabled());
rshutdown();
var_dump(\datadog\appsec\is_enabled());
set_remote_asm_features(null);
rinit(); //Third rinit. The ASM_FEATURES config is gone
var_dump(\datadog\appsec\is_enabled());
rshutdown();
var_dump(\datadog\appsec\is_enabled());

// Only the enabled request talked to the helper
echo implode(", ", array_column($helper->get_commands(), 0)), "\n";
?>
--EXPECTF--
bool(false)
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
bool(false)
request_init, request_shutdown
