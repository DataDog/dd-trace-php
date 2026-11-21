--TEST--
RINIT fails because the sidecar cannot be reached
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,close_helper_connection};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/logging.php';

$helper = Helper::createRun([
    response_list(response_request_init([[['ok', []]]])),
], ['no_wait_shutdown' => true]);
// Requests go to the sidecar then: tests either have no connection to it, or
// it has no AppSec client for the connection (testing mode sends no AppSec
// session config).
var_dump(close_helper_connection());

echo "rinit:\n";
var_dump(rinit());
echo "rshutdown:\n";
var_dump(rshutdown());
match_log('/Could not send request_init to the sidecar/', '/No AppSec helper client handled request_init/');
echo "is enabled:\n";
var_dump(\datadog\appsec\is_enabled());
?>
--EXPECTF--
bool(true)
rinit:
bool(true)
rshutdown:
bool(true)
found message in log matching %s
is enabled:
bool(true)
