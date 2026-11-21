--TEST--
The AppSec settings of the sidecar connections come from the configuration
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.waf_timeout=42
datadog.appsec.trace_rate_limit=7
datadog.appsec.rules=/some/rules.json
datadog.appsec.helper_log_file=/tmp/helper.log
datadog.appsec.helper_log_level=debug
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_ENABLED=0
DD_API_SECURITY_SAMPLE_DELAY=-5
DD_SERVICE=appsec_tests
DD_ENV=prod
--FILE--
<?php
use function datadog\appsec\testing\{rinit,appsec_session_config};

include __DIR__ . '/inc/mock_helper.php';

// The service and env are not part of them: the sidecar binds each
// connection's helper client to the application the tracer registers.
$config = appsec_session_config();
ksort($config);
print_r($config);

$helper = Helper::createRun([
    response_list(response_request_init([[['ok', []]]]))
], ['no_wait_shutdown' => true]);

var_dump(rinit());
var_dump($helper->get_commands()[0][0]);
?>
--EXPECTF--
Array
(
    [helper_log_file] => /tmp/helper.log
    [helper_log_level] => debug
    [obfuscator_key_regex] => %s
    [obfuscator_value_regex] => %s
    [rules_file] => /some/rules.json
    [schema_extraction] => Array
        (
            [enabled] =>%w
            [sampling_period] => 0
        )

    [trace_rate_limit] => 7
    [waf_timeout_us] => 42
)
bool(true)
string(12) "request_init"
