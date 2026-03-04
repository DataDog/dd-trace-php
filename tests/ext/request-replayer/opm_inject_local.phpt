--TEST--
OPM from agent /info is lazily fetched and injected in outbound Datadog and W3C headers
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php
$ctx = stream_context_create([
    'http' => [
        'method' => 'PUT',
        "header" => [
            "Content-Type: application/json",
            "X-Datadog-Test-Session-Token: opm_inject_local",
        ],
        'content' => '{"opm":"agent-org-opm"}'
    ]
]);
file_get_contents("http://request-replayer/set-agent-info", false, $ctx);
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
--INI--
datadog.trace.agent_test_session_token=opm_inject_local
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

// OPM is lazily fetched on first call to generate_distributed_tracing_headers().
// Poll until local OPM becomes available from agent info via the sidecar.
$local_opm = null;
for ($i = 0; $i < $rr->maxIteration && $local_opm === null; $i++) {
    $headers = DDTrace\generate_distributed_tracing_headers(["datadog", "tracecontext"]);
    $local_opm = $headers['x-dd-opm'] ?? null;
    if ($local_opm === null) {
        usleep($rr->flushInterval);
    }
}

echo "x-dd-opm: " . ($local_opm ?? '<none>') . "\n";
preg_match('/opm:([^;,]+)/', $headers['tracestate'] ?? '', $m);
echo "tracestate opm: " . ($m[1] ?? '<none>') . "\n";

?>
--EXPECT--
x-dd-opm: agent-org-opm
tracestate opm: agent-org-opm
