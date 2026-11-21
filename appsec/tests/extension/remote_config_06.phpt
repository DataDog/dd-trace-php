--TEST--
Test calls to request_shutdown only when enabled
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_SERVICE=appsec_tests
DD_ENV=prod
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown, set_remote_asm_features};

include __DIR__ . '/inc/mock_helper.php';
$helper = Helper::createInitedRun([
    //First request
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])), //End of request
    //Second request: still enabled
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])), //End of request
    //Third request: disabled, the helper is not contacted
    //Fourth request: we reenable it
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])), //End of request
]);

set_remote_asm_features(true);
rinit();
echo "First request:\n";
var_dump(\datadog\appsec\is_enabled());
rshutdown();

rinit();
echo "Second request:\n";
var_dump(\datadog\appsec\is_enabled());
rshutdown();

set_remote_asm_features(false);
rinit();
echo "Third request:\n";
var_dump(\datadog\appsec\is_enabled());
rshutdown();

set_remote_asm_features(true);
rinit();
echo "Fourth request:\n";
rshutdown();
var_dump(\datadog\appsec\is_enabled());

echo implode(", ", array_column($helper->get_commands(), 0)), "\n";
?>
--EXPECTF--
First request:
bool(true)
Second request:
bool(true)
Third request:
bool(false)
Fourth request:
bool(true)
request_init, request_shutdown, request_init, request_shutdown, request_init, request_shutdown
