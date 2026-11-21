--TEST--
Test unexpected scenarios
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--ENV--
DD_SERVICE=appsec_tests
DD_ENV=prod
--FILE--
<?php
include __DIR__ . '/inc/mock_helper.php';

use function datadog\appsec\testing\{rinit, rshutdown, close_helper_connection, set_remote_asm_features};

// Activation comes from the extension's own ASM_FEATURES view; the helper's
// replies cannot change it. A fresh mock helper is started per scenario.
$scenarios = [
    [ //Scenario 1: request_init gets a config_features reply, which is no longer part of the protocol: the request is skipped but appsec stays enabled
        'enabled' => true,
        'responses' => [
            response_list(response_config_features(false)),
        ],
    ],
    [ //Scenario 2: request_init gets a config_sync reply (unexpected): the request is skipped but appsec stays enabled
        'enabled' => true,
        'responses' => [
            response_list(response_config_sync()),
        ],
    ],
    [ //Scenario 3: disabled by remote config; the helper is not contacted at all
        'enabled' => false,
        'responses' => [
            response_list(response_request_init([[['ok', []]]])),
        ],
    ],
    [ //Scenario 4: enabled again; regular request
        'enabled' => true,
        'responses' => [
            response_list(response_request_init([[['ok', []]]])),
        ],
    ],
];

foreach ($scenarios as $key => $scenario)
{
    $helper = Helper::createInitedRun($scenario['responses']);
    set_remote_asm_features($scenario['enabled']);
    echo "Scenario " . ($key + 1) . PHP_EOL;
    echo "Before is: ". (int)\datadog\appsec\is_enabled() . PHP_EOL;
    echo "Rinit returns: ". (int)rinit(). PHP_EOL;
    echo "After is: ". (int)\datadog\appsec\is_enabled() . PHP_EOL;
    $commands = $helper->get_commands();
    echo "Commands: ", is_array($commands) ? implode(", ", array_column($commands, 0)) : "none", PHP_EOL;
    rshutdown();
    close_helper_connection();
    $helper->finished_with_commands();
    unset($helper);
}

?>
--EXPECTF--
Scenario 1
Before is: 0
Rinit returns: 1
After is: 1
Commands: request_init
Scenario 2
Before is: 1
Rinit returns: 1
After is: 1
Commands: request_init
Scenario 3
Before is: 1
Rinit returns: 1
After is: 0
timeout
Commands: none
Scenario 4
Before is: 0
Rinit returns: 1
After is: 1
Commands: request_init
