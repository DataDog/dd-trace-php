--TEST--
No appsec upstream and no attack, set priority to auto reject
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
DD_EXPERIMENTAL_APPSEC_STANDALONE_ENABLED=1
DD_APPSEC_ENABLED=1
DD_TRACE_TRACED_INTERNAL_FUNCTIONS=curl_exec
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_TAGS=_dd.p.other=1
--INI--
extension=ddtrace.so
datadog.trace.agent_test_session_token=background-sender/agent_sampling_a
--SKIPIF--
<?php if (!extension_loaded('curl')) die('skip: curl extension required'); ?>
<?php if (!getenv('HTTPBIN_HOSTNAME')) die('skip: HTTPBIN_HOSTNAME env var required'); ?>
--FILE--
<?php
include __DIR__ . '/simulate_request.inc';
include __DIR__ . '/../inc/mock_helper.php';

$helper = Helper::createInitedRun(array_merge(request_without_events(), request_without_events()));
$rr = new RequestReplayer();

//We need to make two requests. The first one is allow by the sampler, but not the second
$result = simulate_request($rr);
var_dump("First call: Appsec should not be present", isset($result['spans'][0]["meta"]["_dd.p.appsec"]));
var_dump("First call: Sampling priority should be 1", $result['spans'][0]["metrics"]["_sampling_priority_v1"]);
var_dump("First call: Apm should be disabled", $result['spans'][0]["metrics"]["_dd.apm.enabled"]);
var_dump("First call: There should not be header propagation", $result['curl_headers']);

$result = simulate_request($rr);
var_dump("Second call: Appsec should not be present", isset($result['spans'][0]["meta"]["_dd.p.appsec"]));
var_dump("Second call: Sampling priority should be 0", $result['spans'][0]["metrics"]["_sampling_priority_v1"]);
var_dump("Second call: Apm should be disabled", $result['spans'][0]["metrics"]["_dd.apm.enabled"]);
var_dump("Second call: There should not be header propagation", $result['curl_headers']);
?>
--EXPECTF--
string(40) "First call: Appsec should not be present"
bool(false)
string(41) "First call: Sampling priority should be 1"
int(1)
string(34) "First call: Apm should be disabled"
int(0)
string(50) "First call: There should not be header propagation"
array(0) {
}
string(41) "Second call: Appsec should not be present"
bool(false)
string(42) "Second call: Sampling priority should be 0"
int(0)
string(35) "Second call: Apm should be disabled"
int(0)
string(51) "Second call: There should not be header propagation"
array(0) {
}