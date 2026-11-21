--TEST--
request_init without a helper client skips AppSec for the request
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/logging.php';

// The sidecar has no helper client for the connection, e.g. its rules failed
// to load.
$helper = Helper::createRun([
    response_list(response('unavailable', [])),
    response_list(response_request_shutdown([[['ok', []]]])),
]);

var_dump(rinit());
var_dump(rshutdown());

match_log('/No AppSec helper client handled request_init; skipping AppSec for this request/');
// No request_shutdown for a request the helper did not start.
echo implode(", ", array_column($helper->get_commands(), 0)), "\n";
?>
--EXPECTF--
bool(true)
bool(true)
found message in log matching /No AppSec helper client handled request_init; skipping AppSec for this request/
request_init
