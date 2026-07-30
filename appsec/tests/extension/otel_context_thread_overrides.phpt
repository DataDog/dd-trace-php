--TEST--
AppSec consumes OTel Thread Context resource overrides
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
datadog.appsec.enabled=1
--ENV--
DD_SERVICE=configured-service
DD_ENV=configured-env
DD_VERSION=configured-version
--SKIPIF--
<?php
if (PHP_OS_FAMILY !== 'Linux') {
    die('skip Linux OTel contexts only');
}
?>
--FILE--
<?php
use function datadog\appsec\testing\{otel_context, rinit};

include __DIR__ . '/inc/mock_helper.php';

$root = \DDTrace\root_span();
$root->service = 'request-service';
$root->env = 'request-env';
$root->version = 'request-version';

$helper = Helper::createRun([
    response_list(response_request_init([[['ok', []]]]))
]);

var_dump(rinit());

$context = otel_context();
unset($context['runtime_id']);
print_r($context);

$clientInit = $helper->get_commands()[0];
print_r($clientInit[1][6]);
echo 'runtime id matches: ',
    $clientInit[1][7]['runtime_id'] ===
        \datadog\appsec\testing\get_formatted_runtime_id()
        ? "yes\n"
        : "no\n";

$root->service = '';
$root->env = 'second-request-env';
$root->version = '';
$context = otel_context();
unset($context['runtime_id']);
print_r($context);

$root->service = 'third-request-service';
$root->env = '';
$root->version = 'third-request-version';
$context = otel_context();
unset($context['runtime_id']);
print_r($context);
?>
--EXPECT--
bool(true)
Array
(
    [service] => request-service
    [environment] => request-env
    [version] => request-version
)
Array
(
    [service_name] => request-service
    [env_name] => request-env
)
runtime id matches: yes
Array
(
    [service] => configured-service
    [environment] => second-request-env
    [version] => configured-version
)
Array
(
    [service] => third-request-service
    [environment] => configured-env
    [version] => third-request-version
)
