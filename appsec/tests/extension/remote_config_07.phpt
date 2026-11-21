--TEST--
Without DD_SERVICE/DD_ENV, rinit uses the ASM_FEATURES of the target the previous request ended on
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
--ENV--
DD_SERVICE=
DD_ENV=
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown, set_remote_asm_features, dump_user_collection_mode};

include __DIR__ . '/inc/mock_helper.php';
$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()])),
]);

$features = [];
$current = null;

// The tracer follows the remote config of $target
function follow($target) {
    global $features, $current;
    $current = $target;
    set_remote_asm_features($features[$target][0], $features[$target][1], $target);
}

// Remote config delivers ASM_FEATURES for $target
function rc($target, $enabled, $mode) {
    global $features, $current;
    $features[$target] = [$enabled, $mode];
    set_remote_asm_features($enabled, $mode, $target);
    if ($current !== null) {
        follow($current);
    }
}

function request($name, $start_target, $end_target) {
    follow($start_target);
    rinit();
    echo "$name: ", (int)\datadog\appsec\is_enabled(), " ", dump_user_collection_mode(), "\n";
    // The root span gets its service
    follow($end_target);
    rshutdown();
}

rc("/rc-app", true, "identification");
rc("/rc-default", false, "disabled");

// The first request has no earlier target to go by
request("First", "/rc-app", "/rc-app");

// ddtrace's rinit targets remote config at a default service
request("Second", "/rc-default", "/rc-app");

// Changes for the request's application in between apply right away
rc("/rc-app", false, "anonymization");
request("Third", "/rc-default", "/rc-app");

// A request ending on another target moves the next one there
rc("/rc-default", true, "identification");
request("Fourth", "/rc-default", "/rc-default");
request("Fifth", "/rc-app", "/rc-app");

echo implode(", ", array_column($helper->get_commands(), 0)), "\n";
?>
--EXPECTF--
First: 1 identification
Second: 1 identification
Third: 0 anonymization
Fourth: 0 anonymization
Fifth: 1 identification
request_init, request_shutdown, request_init, request_shutdown, request_init, request_shutdown
