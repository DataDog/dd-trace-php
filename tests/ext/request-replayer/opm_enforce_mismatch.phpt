--TEST--
Foreign OPM triggers enforcement: origin, tags and priority are cleared, trace_id and parent_id are kept
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php
$ctx = stream_context_create([
    'http' => [
        'method' => 'PUT',
        "header" => [
            "Content-Type: application/json",
            "X-Datadog-Test-Session-Token: opm_enforce_mismatch",
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
--INI--
datadog.trace.agent_test_session_token=opm_enforce_mismatch
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

// Consume inbound headers carrying a foreign OPM. With local OPM now cached,
// enforcement fires and clears origin, propagated tags and sampling priority.
DDTrace\consume_distributed_tracing_headers([
    "x-datadog-trace-id"         => "123456789",
    "x-datadog-parent-id"        => "987654321",
    "x-datadog-sampling-priority" => "2",
    "x-datadog-origin"           => "foreign-org",
    "x-datadog-tags"             => "_dd.p.custom=value",
    "x-dd-opm"                   => "foreign-opm",
]);

$out = DDTrace\generate_distributed_tracing_headers(["datadog"]);

// trace_id and parent_id must be preserved across enforcement
echo "trace_id kept: "    . ($out['x-datadog-trace-id']  === "123456789" ? "yes" : "no") . "\n";
// origin must be cleared by enforcement
echo "origin cleared: "   . (!isset($out['x-datadog-origin'])            ? "yes" : "no") . "\n";
// propagated tags (_dd.p.custom) must be cleared by enforcement
$tags = $out['x-datadog-tags'] ?? '';
echo "custom tag cleared: " . (strpos($tags, '_dd.p.custom') === false   ? "yes" : "no") . "\n";
// sampling priority 2 (user-keep) must be reset, auto-decided to 1
echo "priority reset: "   . (($out['x-datadog-sampling-priority'] ?? '') !== "2" ? "yes" : "no") . "\n";
// local OPM is still forwarded in the outbound header
echo "local opm injected: " . (($out['x-dd-opm'] ?? '') === 'local-opm-value' ? "yes" : "no") . "\n";

?>
--EXPECT--
trace_id kept: yes
origin cleared: yes
custom tag cleared: yes
priority reset: yes
local opm injected: yes
