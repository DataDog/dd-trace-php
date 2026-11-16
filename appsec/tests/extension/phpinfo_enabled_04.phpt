--TEST--
Check enablement status when configured by remote config
--FILE--
<?php
include __DIR__ . '/inc/phpinfo.php';
use function datadog\appsec\testing\{rinit, rshutdown};

include __DIR__ . '/inc/mock_helper.php';
$helper = Helper::createInitedRun([
    response_list(response_config_features(true)), //First rinit enables
    response_list(response_request_init([[['ok', []]]])), //Since it got enabled, it should call to request init
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])), //End of request
    response_list(response_config_features(false)), //Second call at rinit disabled it
    response_list(response_config_sync()), //Third call which does not change anything
]);

//Enabled not configured
var_dump(get_configuration_value("State managed by remote config"));
var_dump(get_configuration_value("Current state"));
rinit(); //On this rinit it gets enabled
var_dump(get_configuration_value("State managed by remote config"));
var_dump(get_configuration_value("Current state"));
rshutdown();
rinit(); //Second rinit. This time it got disabled
var_dump(get_configuration_value("State managed by remote config"));
var_dump(get_configuration_value("Current state"));
rshutdown();
rinit(); //Third rinit. Nothing changes
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
