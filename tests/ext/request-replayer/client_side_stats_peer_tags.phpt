--TEST--
Client-side SHM span stats include peer tags configured via agent info
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php
if (PHP_VERSION_ID < 70400) die("skip: Before PHP 7.4, the skip-task would cause the sidecar to fetch the info already.");
if (PHP_VERSION_ID >= 80100) {
    echo "nocache\n";
}
$ctx = stream_context_create([
    'http' => [
        'method' => 'PUT',
        'header' => [
            'Content-Type: application/json',
            'X-Datadog-Test-Session-Token: client_side_stats_peer_tags',
        ],
        'content' => json_encode(['version' => '7.65.0', 'client_drop_p0s' => true, 'peer_tags' => ['db.hostname']]),
    ]
]);
file_get_contents('http://request-replayer/set-agent-info', false, $ctx);
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_TRACE_STATS_COMPUTATION_ENABLED=1
DD_TRACE_LOG_LEVEL=off
--INI--
datadog.env=test-env
datadog.version=1.2.3-peer
datadog.trace.agent_test_session_token=client_side_stats_peer_tags
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

// Flush a dummy trace and wait for the sidecar to process it.  The sidecar polls the agent
// /info endpoint on the same interval, so by the time we return here it will have written
// the peer_tags from the agent info (set in SKIPIF) to the shared-memory segment that
// ddog_apply_agent_info_concentrator_config reads.
$dummy = \DDTrace\start_trace_span();
$dummy->name = "dummy";
$dummy->service = "dummy-service";
\DDTrace\close_span();
dd_trace_internal_fn('synchronous_flush');
$rr->waitForDataAndReplay();

// Now create the span whose stats we want to inspect. When this span is fed to the
// concentrator, ddog_apply_agent_info_concentrator_config() is called first, picks up
// the peer_tags update from the SHM, and the concentrator extracts db.hostname from meta.
$root = \DDTrace\start_trace_span();
$root->name = "web.request";
$root->resource = "GET /db";
$root->service = "stats-test-service";
$root->meta['span.kind'] = 'client';
$root->meta['db.hostname'] = 'my-db-host';
\DDTrace\close_span();

dd_trace_internal_fn('synchronous_flush');
$rr->waitForDataAndReplay();

// SKIPIF also generates stats (file_get_contents span) under this token; use a matcher
// so we find the stats payload that actually contains our service.
$statsRequest = $rr->waitForStats(function ($request) {
    $payload = json_decode($request['body'], true);
    foreach ($payload['Stats'] ?? [] as $bucket) {
        foreach ($bucket['Stats'] ?? [] as $group) {
            if ($group['Service'] === 'stats-test-service' && $group['Name'] === 'web.request') {
                return true;
            }
        }
    }
    return false;
});

$payload = json_decode($statsRequest['body'], true);
$found = false;
foreach ($payload['Stats'] as $bucket) {
    foreach ($bucket['Stats'] as $group) {
        if ($group['Service'] === 'stats-test-service' && $group['Name'] === 'web.request') {
            $peerTags = $group['PeerTags'] ?? [];
            sort($peerTags);
            echo "peer_tags: " . json_encode($peerTags) . "\n";
            $found = true;
            break 2;
        }
    }
}
if (!$found) {
    echo "ERROR: no matching stats group found\n";
    var_dump($payload);
}

?>
--EXPECT--
peer_tags: ["db.hostname:my-db-host"]
