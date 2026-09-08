--TEST--
Test unexpected scenarios
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
include __DIR__ . '/inc/mock_helper.php';

use function datadog\appsec\testing\{rinit, reset_backoff, close_helper_connection};

// On the sidecar flow, the first rinit on a fresh connection sends
// client_init -> config_sync -> request_init, and config_sync is the command
// that carries the remote-config (config_features) enable/disable reply. Each
// scenario therefore runs on its own connection: reset_backoff() clears the
// retry suppression left by a scenario that intentionally drops the connection,
// and a fresh mock helper is started per scenario. appsec activation state
// (is_enabled) persists across scenarios -- that cross-scenario carry-over is
// the contract being checked.
$scenario_calls = [
    [ //Scenario 1: config_sync gets an unexpected reply (request_init); the connection is dropped and appsec stays disabled
        response_list(response_request_init([[['ok', []]]])),
    ],
    [ //Scenario 2: config_sync gets config_features disabled
        response_list(response_config_features(false)),
    ],
    [ //Scenario 3: config_sync enables via config_features, then request_init disables again
        response_list(response_config_features(true)),
        response_list(response_config_features(false)),
    ],
    [ //Scenario 4: config_sync enables; request_init then gets a config_sync reply (unexpected) and the connection drops, but appsec stays enabled
       response_list(response_config_features(true)),
       response_list(response_config_sync()),
    ],
    [ //Scenario 5: config_sync enables via config_features
       response_list(response_config_features(true)),
       response_list(response_request_init([[['ok', []]]])),
    ],
];

function run_scenario($number)
{
    echo "Scenario $number" . PHP_EOL;
    echo "Before is: ". (int)\datadog\appsec\is_enabled() . PHP_EOL;
    echo "Rinit returns: ". (int)rinit(). PHP_EOL;
    echo "After is: ". (int)\datadog\appsec\is_enabled() . PHP_EOL;
}

$helper = null;
foreach ($scenario_calls as $key => $scenario_responses)
{
    reset_backoff();
    $helper = Helper::createInitedRun($scenario_responses);
    run_scenario($key + 1);
    close_helper_connection();
    $helper->finished_with_commands();
    unset($helper);
}

?>
--EXPECTF--
Scenario 1
Before is: 0
Rinit returns: 1
After is: 0
Scenario 2
Before is: 0
Rinit returns: 1
After is: 0
Scenario 3
Before is: 0
Rinit returns: 1
After is: 0
Scenario 4
Before is: 0
Rinit returns: 1
After is: 1
Scenario 5
Before is: 1
Rinit returns: 1
After is: 1