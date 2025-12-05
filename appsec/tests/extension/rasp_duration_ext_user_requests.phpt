--TEST--
RASP duration_ext metric accumulates across multiple calls in user requests
--INI--
extension=ddtrace.so
datadog.appsec.enabled=true
datadog.appsec.cli_start_on_rinit=false
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

use function DDTrace\UserRequest\{notify_start,notify_commit};
use function DDTrace\{start_span,close_span,active_span};
use function datadog\appsec\push_addresses;

include __DIR__ . '/inc/mock_helper.php';

define('NUM_CALLS', 20);

$resps = array_merge(
    array(response_list(response_request_init([[['ok', []]], [], []]))),
    array_fill(0, NUM_CALLS,
    response_list(response_request_exec([[['ok', []]], [], [], [], false]))),
    array(
        response_list(response_request_shutdown([[['ok', []]], [], []])),

        // Second user request
        response_list(response_request_init([[['ok', []]], [], []])),
        response_list(response_request_exec([[['ok', []]], [], [], [], false])),
        response_list(response_request_shutdown([[['ok', []]], [], []])),
    )
);

$helper = Helper::createinitedRun($resps);

echo "=== First user request ===\n";
$span1 = start_span();
notify_start($span1, array());

for ($i = 0; $i < NUM_CALLS; $i++) {
    push_addresses(["server.request.path_params" => "test1"], "lfi");
}

notify_commit($span1, 200, array());

// Get metrics for first request
$metrics1 = $span1->metrics ?? [];
echo "First request has duration_ext: " . (isset($metrics1['_dd.appsec.rasp.duration_ext']) ? "yes" : "no") . "\n";
if (isset($metrics1['_dd.appsec.rasp.duration_ext'])) {
    $duration1 = $metrics1['_dd.appsec.rasp.duration_ext'];
    echo "First request duration_ext is positive: " . ($duration1 > 0 ? "yes" : "no") . "\n";
}

close_span(100.0);

echo "\n=== Second user request ===\n";
$span2 = start_span();
notify_start($span2, array());

push_addresses(["server.request.body" => "test3"], "lfi");

notify_commit($span2, 200, array());

// Get metrics for second request
$metrics2 = $span2->metrics ?? [];
echo "Second request has duration_ext: " . (isset($metrics2['_dd.appsec.rasp.duration_ext']) ? "yes" : "no") . "\n";
if (isset($metrics2['_dd.appsec.rasp.duration_ext'])) {
    $duration2 = $metrics2['_dd.appsec.rasp.duration_ext'];
    echo "Second request duration_ext is positive: " . ($duration2 > 0 ? "yes" : "no") . "\n";
}

close_span(100.0);

if (isset($duration1) && isset($duration2)) {
    echo "\nBoth requests have duration_ext metrics: yes\n";
    if ($duration1 > $duration2) {
        echo "First request has a larger duration_ext: yes\n";
    }
}


?>
--EXPECTF--
=== First user request ===
First request has duration_ext: yes
First request duration_ext is positive: yes

=== Second user request ===
Second request has duration_ext: yes
Second request duration_ext is positive: yes

Both requests have duration_ext metrics: yes
First request has a larger duration_ext: yes
