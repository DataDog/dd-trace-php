--TEST--
Test when helper responds with error
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';
require __DIR__ . '/inc/logging.php';

$helper = Helper::createInitedRun([
    response_list(response("error", []))
]);

var_dump(rinit());

match_log("/Helper responded with an error\. Check helper logs/");
match_log("/request init failed: dd_helper_error/");

?>
--EXPECTF--
bool(true)
found message in log matching /Helper responded with an error\. Check helper logs/
found message in log matching /request init failed: dd_helper_error/

