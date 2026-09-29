--TEST--
request_exec logs and rejects empty data without sending a command
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.log_file=php_error_reporting
--FILE--
<?php
use function datadog\appsec\testing\{rinit, request_exec};
use function datadog\appsec\push_addresses;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['ok', []]]])),
], ['continuous' => true]);

rinit();
$helper->get_commands();

ini_set('datadog.appsec.log_level', 'info');
var_dump(request_exec([]));
push_addresses([]);
ini_set('datadog.appsec.log_level', 'warning');
var_dump(request_exec(['server.request.path_params' => []]));

foreach ($helper->get_commands() as $command) {
    echo $command[0], ': ', json_encode($command[1][0]), "\n";
}

$helper->finished_with_commands();
?>
--EXPECTF--
Notice: datadog\appsec\testing\request_exec(): [ddappsec] Skipping request_exec with empty payload in %s on line %d
bool(false)

Notice: datadog\appsec\push_addresses(): [ddappsec] Skipping request_exec with empty payload in %s on line %d
bool(true)
request_exec: {"server.request.path_params":[]}
