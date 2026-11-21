--TEST--
Check enablement status when configured by remote config
--ENV--
DD_SERVICE=appsec_tests
DD_ENV=prod
--FILE--
<?php
include __DIR__ . '/inc/phpinfo.php';
use function datadog\appsec\testing\{rinit, rshutdown, set_remote_asm_features};

include __DIR__ . '/inc/mock_helper.php';
$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])), //End of request
]);

//Enabled not configured
var_dump(get_configuration_value("State managed by remote config"));
var_dump(get_configuration_value("Current state"));
set_remote_asm_features(true);
rinit(); //On this rinit it gets enabled
var_dump(get_configuration_value("State managed by remote config"));
var_dump(get_configuration_value("Current state"));
rshutdown();
set_remote_asm_features(false);
rinit(); //Second rinit. This time it got disabled
var_dump(get_configuration_value("State managed by remote config"));
var_dump(get_configuration_value("Current state"));
rshutdown();
rinit(); //Third rinit. Nothing changes
rshutdown();
var_dump(get_configuration_value("State managed by remote config"));
var_dump(get_configuration_value("Current state"));
set_remote_asm_features(null);
rinit(); //Fourth rinit. No ASM_FEATURES config sets it anymore
rshutdown();
var_dump(get_configuration_value("State managed by remote config"));
var_dump(get_configuration_value("Current state"));
--EXPECT--
string(3) "Yes"
string(14) "Not configured"
string(3) "Yes"
string(7) "Enabled"
string(3) "Yes"
string(8) "Disabled"
string(3) "Yes"
string(8) "Disabled"
string(3) "Yes"
string(14) "Not configured"
