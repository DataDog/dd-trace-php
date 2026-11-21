--TEST--
Test extension is enabled if not disabled explicitly and rc enables it
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown, set_remote_asm_features};
include __DIR__ . '/inc/mock_helper.php';

//Lets verify it calls to helper which mean, extension is enabled
$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
]);

//Enabled not configured, therefore it is not enabled
var_dump(\datadog\appsec\is_enabled());
set_remote_asm_features(true);
rinit(); //On this rinit it gets enabled
var_dump(\datadog\appsec\is_enabled());
echo implode(", ", array_column($helper->get_commands(), 0)), "\n";
rshutdown();
?>
--EXPECTF--
bool(false)
bool(true)
request_init
