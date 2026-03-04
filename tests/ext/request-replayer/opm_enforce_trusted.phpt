--TEST--
OPM listed in DD_TRACE_ORG_GUARD_TRUSTED_OPM keeps the distributed context intact
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php
$ctx = stream_context_create([
    'http' => [
        'method' => 'PUT',
        "header" => [
            "Content-Type: application/json",
            "X-Datadog-Test-Session-Token: opm_enforce_trusted",
        ],
        'content' => '{"opm":"local-opm-value"}'
    ]
]);
file_get_contents("http://request-replayer/set-agent-info", false, $ctx);
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_ORG_GUARD_TRUSTED_OPM=trusted-partner-opm
--INI--
datadog.trace.agent_test_session_token=opm_enforce_trusted
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

// Trigger lazy OPM fetch; poll until the local OPM is cached in the module global.
$got_opm = false;
for ($i = 0; $i < $rr->maxIteration && !$got_opm; $i++) {
    $h = DDTrace\generate_distributed_tracing_headers(["datadog"]);
    $got_opm = isset($h['x-dd-opm']);
    if (!$got_opm) {
        usleep($rr->flushInterval);
    }
}

// Consume inbound headers carrying a trusted partner OPM. Even though the local
// OPM is different, enforcement must not fire because the inbound OPM is trusted.
DDTrace\consume_distributed_tracing_headers([
    "x-datadog-trace-id"         => "123456789",
    "x-datadog-parent-id"        => "987654321",
    "x-datadog-sampling-priority" => "2",
    "x-datadog-origin"           => "trusted-org",
    "x-datadog-tags"             => "_dd.p.custom=value",
    "x-dd-opm"                   => "trusted-partner-opm",
]);

$out = DDTrace\generate_distributed_tracing_headers(["datadog"]);

// All context must be preserved since the OPM is in the trusted list
echo "trace_id kept: "    . ($out['x-datadog-trace-id']  === "123456789"    ? "yes" : "no") . "\n";
echo "origin kept: "      . (($out['x-datadog-origin']   ?? '') === "trusted-org" ? "yes" : "no") . "\n";
$tags = $out['x-datadog-tags'] ?? '';
echo "custom tag kept: "  . (strpos($tags, '_dd.p.custom=value') !== false   ? "yes" : "no") . "\n";
echo "priority kept: "    . (($out['x-datadog-sampling-priority'] ?? '') === "2" ? "yes" : "no") . "\n";

?>
--EXPECT--
trace_id kept: yes
origin kept: yes
custom tag kept: yes
priority kept: yes
