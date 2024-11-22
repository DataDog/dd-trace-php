--TEST--
No appsec upstream with attack, set priority to keep
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=1
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
DD_EXPERIMENTAL_APPSEC_STANDALONE_ENABLED=1
DD_APPSEC_ENABLED=1
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=curl_exec
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_TAGS=_dd.p.other=1
--INI--
extension=ddtrace.so
datadog.trace.agent_test_session_token=background-sender/agent_sampling_b
--FILE--
<?php
include __DIR__ . '/simulate_request.inc';
include __DIR__ . '/../inc/mock_helper.php';

$helper = Helper::createInitedRun([
    ...request_with_events()
]);

$rr = new RequestReplayer();
$result = simulate_request($rr);
var_dump("Appsec should be present", isset($result['spans'][0]["meta"]["_dd.p.appsec"]));
var_dump("Sampling priority should be 2", $result['spans'][0]["metrics"]["_sampling_priority_v1"]);
var_dump("Apm should be disabled", $result['spans'][0]["metrics"]["_dd.apm.enabled"]);
var_dump("Propagated sampling priority should be 2", $result['curl_headers']['x-datadog-sampling-priority']);
var_dump("Appsec tag should be propagated", strpos($result['curl_headers']['x-datadog-tags'], '_dd.p.appsec=1') !== false);
?>
--EXPECTF--
string(24) "Appsec should be present"
bool(true)
string(29) "Sampling priority should be 2"
int(2)
string(22) "Apm should be disabled"
int(0)
string(40) "Propagated sampling priority should be 2"
string(1) "2"
string(31) "Appsec tag should be propagated"
bool(true)