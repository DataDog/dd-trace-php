--TEST--
Test auto user instrum mode parsing with disabled default value.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_SERVICE=appsec_tests
DD_ENV=prod
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=disabled
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown, set_remote_asm_features};
use function datadog\appsec\testing\dump_user_collection_mode;

echo "Default:\n";
var_dump(dump_user_collection_mode());

set_remote_asm_features(null, 'identification');
rinit();
echo "First request:\n";
var_dump(dump_user_collection_mode());
rshutdown();

set_remote_asm_features(null, 'disabled');
rinit();
echo "Second request:\n";
var_dump(dump_user_collection_mode());
rshutdown();

set_remote_asm_features(null, 'anonymization');
rinit();
echo "Third request:\n";
var_dump(dump_user_collection_mode());
rshutdown();

set_remote_asm_features(null, 'unknown');
rinit();
echo "Fourth request:\n";
var_dump(dump_user_collection_mode());
rshutdown();

set_remote_asm_features(null, 'undefined');
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

Warning: datadog\appsec\testing\rinit(): [ddappsec] Unknown remote config user collection mode; disabling user ID collection in %s on line %d
Fourth request:
string(8) "disabled"
Fifth request:
string(8) "disabled"
