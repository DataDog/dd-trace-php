--TEST--
Client init with sidecar activated sends service/env
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.waf_timeout=42
datadog.appsec.log_level=debug
datadog.appsec.testing_raw_body=1
datadog.appsec.enabled=1
datadog.trace.agent_port=18126
datadog.extra_services=,some,extra,services,
--ENV--
DD_INSTRUMENTATION_TELEMETRY_ENABLED=1
DD_VERSION=1.1
DD_SERVICE=appsec_tests
DD_ENV=prod
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createRun([
    response_list(response_request_init([[['ok', []]]]))
]);

var_dump(rinit());

$client_init = $helper->get_commands()[0];
print_r($client_init[1][5]);
print_r($client_init[1][6]);
?>
--EXPECTF--
bool(true)
Array
(
    [enabled] => 1
    [shmem_path] => /ddrc%s
)
Array
(
    [service_name] => appsec_tests
    [env_name] => prod
)
[ddtrace] [error] Failed flushing filtered telemetry buffer: %s
[ddtrace] [error] Failed removing application from sidecar: %s
