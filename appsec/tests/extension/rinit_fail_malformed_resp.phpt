--TEST--
RINIT fails because helper sent a malformed response
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/logging.php';

// A failed request_init skips AppSec for the request: rshutdown won't
// communicate with the helper.
$helper = Helper::createRun([
    response_list(
        response_request_init([['foo' => 'ok']])
    ),
]);

echo "rinit:\n";
var_dump(rinit());
echo "rshutdown:\n";
var_dump(rshutdown());
match_log('/The AppSec helper failed request_init: malformed mock response to "request_init"/');
echo implode(", ", array_column($helper->get_commands(), 0)), "\n";
?>
--EXPECTF--
rinit:
bool(true)
rshutdown:
bool(true)
found message in log matching /The AppSec helper failed request_init: malformed mock response to "request_init"/
request_init
