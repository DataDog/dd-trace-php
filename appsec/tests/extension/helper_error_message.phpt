--TEST--
Test when helper responds with error
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/logging.php';

$helper = Helper::createRun([
    response_list(response("error", []))
], ['no_wait_shutdown' => true]);

var_dump(rinit());
var_dump(rshutdown());

match_log("/The AppSec helper failed request_init: the helper responded with an error/");
// The request was not started: no request_shutdown.
echo implode(", ", array_column($helper->get_commands(), 0)), "\n";
?>
--EXPECTF--
bool(true)
bool(true)
found message in log matching /The AppSec helper failed request_init: the helper responded with an error/
request_init
