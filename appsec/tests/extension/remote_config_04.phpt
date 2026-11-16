--TEST--
Test extension is enabled if not disabled explicitly and rc not configured
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown};
include __DIR__ . '/inc/mock_helper.php';

//Lets verify it calls to helper which mean, extension is enabled
$helper = Helper::createInitedRun([
    response_list(response_config_features(true))
]);

//Enabled not configured, therefore it is not enabled
var_dump(\datadog\appsec\is_enabled());
rinit(); //On this rinit it gets enabled
var_dump(\datadog\appsec\is_enabled());
rshutdown();
?>
--EXPECTF--
bool(false)
bool(true)
