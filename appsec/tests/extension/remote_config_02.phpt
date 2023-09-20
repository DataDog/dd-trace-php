--TEST--
Test unexpected scenarios
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--FILE--
<?php
include __DIR__ . '/inc/mock_helper.php';

use function datadog\appsec\testing\{rinit, rshutdown};

$scenario_calls = [
    [ //Scenario 1 It would expect here a config sync or config features but not this one
        response_list(response_request_init(['ok'])),
    ],
    [ //Scenario 2 Config_sync gets config_features disabled
        response_list(response_config_features(false)),
    ],
    [ //Scenario 3 It gets enabled by RC with a config features response but on following request_init call it gets disabled again
        response_list(response_config_features(true)),
        response_list(response_config_features(false)),
    ],
    [ //Scenario 4 It gets enabled but request_init gets config_sync instead
       response_list(response_config_features(true)),
       response_list(response_config_sync()),
    ],
    [ //Scenario 5 Test request_init gets config_features enabled
       response_list(response_config_features(true)),
    ],
];


$all_responses = [];
foreach ($scenario_calls as $scenarios) {
    $all_responses = array_merge($all_responses, $scenarios);
}

$helper = Helper::createInitedRun($all_responses);

function run_scenario($number)
{
    echo "Scenario $number" . PHP_EOL;
    echo "Before is: ". (int)\datadog\appsec\is_enabled() . PHP_EOL;
    echo "Rinit returns: ". (int)rinit(). PHP_EOL;
    echo "After is: ". (int)\datadog\appsec\is_enabled() . PHP_EOL;
}

foreach ($scenario_calls as $key => $value)
{
    run_scenario($key + 1);
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