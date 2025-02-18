--TEST--
Test auto user instrum mode parsing with disabled default value.
--ENV--
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=disabled
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown};
use function datadog\appsec\testing\dump_user_collection_mode;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    // Firt request
    response_list(response_config_features(true)), // enabled
    response_list(response_request_init([[['ok', []]], [], false, ['auto_user_instrum' => 'identification']])),
    response_list(response_request_shutdown([[['ok', []]]])), //End of request
    // Second request
    response_list(response_request_init([[['ok', []]], [], false, ['auto_user_instrum' => 'disabled']])),
    response_list(response_request_shutdown([[['ok', []]]])), //End of request
    // Third request
    response_list(response_request_init([[['ok', []]], [], false, ['auto_user_instrum' => 'anonymization']])),
    response_list(response_request_shutdown([[['ok', []]]])), //End of request
    // Fourth request
    response_list(response_request_init([[['ok', []]], [], false, ['auto_user_instrum' => 'unknown']])),
    response_list(response_request_shutdown([[['ok', []]]])), //End of request
    // Fifth request
    response_list(response_request_init([[['ok', []]], [], false, ['auto_user_instrum' => 'undefined']])),
    response_list(response_request_shutdown([[['ok', []]]])), //End of request
]);

echo "Default:\n";
var_dump(dump_user_collection_mode());

rinit();
echo "First request:\n";
var_dump(dump_user_collection_mode());
rshutdown();

rinit();
echo "Second request:\n";
var_dump(dump_user_collection_mode());
rshutdown();

rinit();
echo "Third request:\n";
var_dump(dump_user_collection_mode());
rshutdown();

rinit();
echo "Fourth request:\n";
var_dump(dump_user_collection_mode());
rshutdown();

rinit();
echo "Fifth request:\n";
var_dump(dump_user_collection_mode());
rshutdown();
?>
--EXPECTF--
Default:
string(8) "disabled"
First request:
string(14) "identification"
Second request:
string(8) "disabled"
Third request:
string(13) "anonymization"
Fourth request:
string(8) "disabled"
Fifth request:
string(8) "disabled"
