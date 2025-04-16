--TEST--
Test calls to request_shutdown only when enabled
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown};

include __DIR__ . '/inc/mock_helper.php';
$helper = Helper::createInitedRun([
    //Firt request
    response_list(response_config_features(true)), // enabled
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])), //End of request
    //Second request: still enabled; no config sync
    response_list(response_request_init([[['ok', []]]])), //Since it got enabled, it should call to request init
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])), //End of request
    //Third request: we disable it in request_init
    response_list(response_config_features(false)),
    //Fourth request: we reenable it
    response_list(response_config_features(true)), // enabled
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])), //End of request
]);

rinit();
echo "First request:\n";
var_dump(\datadog\appsec\is_enabled());
rshutdown();

rinit();
echo "Second request:\n";
var_dump(\datadog\appsec\is_enabled());
rshutdown();

rinit();
echo "Third request:\n";
var_dump(\datadog\appsec\is_enabled());
rshutdown();

rinit();
echo "Fourth request:\n";
rshutdown();
var_dump(\datadog\appsec\is_enabled());
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
