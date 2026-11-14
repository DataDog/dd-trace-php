--TEST--
DSM2-611: the APM_TRACING_DATA_STREAMS_ENABLED capability is reported, and the key it announces is applied
--DESCRIPTION--
Capability 20 tells the backend this tracer takes data_streams_enabled; the two must not drift apart.
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_REMOTE_CONFIG_POLL_INTERVAL_SECONDS=0.1
DD_TRACE_AGENT_TEST_SESSION_TOKEN=remote-config/dsm_rc_capability
--FILE--
<?php

require __DIR__ . "/dsm_remote_config.inc";
include __DIR__ . '/../includes/request_replayer.inc';

const APM_TRACING_DATA_STREAMS_ENABLED = 20;

// The capabilities are a big-endian bit mask: capability n is bit n % 8 of byte n / 8 from the end.
function has_capability($encoded, $capability) {
    // The agent protocol has a base64 string; the request replayer shows the raw bytes as a list.
    $bytes = is_array($encoded) ? $encoded : array_map('ord', str_split(base64_decode($encoded)));
    $bytes = array_reverse($bytes);
    return isset($bytes[$capability >> 3]) && (($bytes[$capability >> 3] >> ($capability & 7)) & 1) === 1;
}

reset_request_replayer();
$rr = new RequestReplayer();
\DDTrace\start_span();

$path = dsm_put_config(["data_streams_enabled" => true], 0.51);

$request = $rr->waitForRcRequest(function ($req) {
    if (strpos($req["uri"], '/v0.7/config') === false) {
        return false;
    }
    $body = json_decode($req["body"], true);
    return isset($body["client"]["capabilities"]);
});
$body = json_decode($request["body"], true);
var_dump(has_capability($body["client"]["capabilities"], APM_TRACING_DATA_STREAMS_ENABLED));
dsm_state("announced key applied");

?>
--CLEAN--
<?php
require __DIR__ . "/remote_config.inc";
reset_request_replayer();
?>
--EXPECT--
bool(true)
announced key applied: setting=on checkpoint=set
