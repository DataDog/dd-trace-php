--TEST--
Client-side stats respect trace filters (filter_tags, filter_tags_regex, ignore_resources) from agent info
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php
if (PHP_VERSION_ID < 70400) die("skip: Before PHP 7.4, the skip-task would cause the sidecar to fetch the info already.");
if (PHP_VERSION_ID >= 80100) {
    echo "nocache\n";
}
// Configure the request-replayer to return these filter rules from the /info endpoint.
// The sidecar will pick them up on its next poll cycle (triggered by the dummy flush below).
//
// Filters configured:
//   filter_tags.require:        filter_required:yes
//   filter_tags.reject:         filter_reject:yes
//   filter_tags_regex.require:  http.method matching G.* (GET passes, DELETE fails)
//   filter_tags_regex.reject:   http.url matching .*\.internal\..*
//   ignore_resources:           GET /healthcheck  (exact resource match)
$ctx = stream_context_create([
    'http' => [
        'method' => 'PUT',
        'header' => [
            'Content-Type: application/json',
            'X-Datadog-Test-Session-Token: client_side_stats_trace_filters',
        ],
        'content' => json_encode([
            'version' => '7.65.0',
            'client_drop_p0s' => true,
            'filter_tags' => [
                'require' => ['filter_required:yes'],
                'reject'  => ['filter_reject:yes'],
            ],
            'filter_tags_regex' => [
                'require' => ['http.method:G.*'],
                'reject'  => ['http.url:.*\\.internal\\..*'],
            ],
            'ignore_resources' => ['GET /healthcheck'],
        ]),
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
datadog.version=1.2.3-filters
datadog.trace.agent_test_session_token=client_side_stats_trace_filters
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

// Block until the sidecar has received the agent's /info response before stats are computed
dd_trace_internal_fn('await_agent_info');

// Each test case is a separate root span (= separate trace), because trace filters are
// evaluated per trace (root span properties / tags).
function makeSpan(string $name, string $resource, array $meta): void {
    $s = \DDTrace\start_trace_span();
    $s->name     = $name;
    $s->resource = $resource;
    $s->service  = 'filter-test-service';
    foreach ($meta as $k => $v) {
        $s->meta[$k] = $v;
    }
    \DDTrace\close_span();
}

// 1. PASS — satisfies every filter.
makeSpan('op.pass', 'GET /api', [
    'filter_required' => 'yes',
    'http.method'     => 'GET',
]);

// 2. BLOCKED by ignore_resources — resource "GET /healthcheck" matches the pattern.
makeSpan('op.blocked.resource', 'GET /healthcheck', [
    'filter_required' => 'yes',
    'http.method'     => 'GET',
]);

// 3. BLOCKED by filter_tags.require — missing required tag "filter_required:yes".
makeSpan('op.blocked.missing_require', 'GET /other', [
    'http.method' => 'GET',
]);

// 4. BLOCKED by filter_tags.reject — tag "filter_reject:yes" triggers exact rejection.
makeSpan('op.blocked.reject_tag', 'GET /other2', [
    'filter_required' => 'yes',
    'filter_reject'   => 'yes',
    'http.method'     => 'GET',
]);

// 5. BLOCKED by filter_tags_regex.reject — http.url matches ".*\.internal\..*".
makeSpan('op.blocked.regex_reject', 'GET /other3', [
    'filter_required' => 'yes',
    'http.method'     => 'GET',
    'http.url'        => 'http://my.internal.service/path',
]);

// 6. BLOCKED by filter_tags_regex.require — http.method is "DELETE" which does not
//    match "G.*" (anchored, so "GET" passes but "DELETE" fails).
makeSpan('op.blocked.regex_require', 'GET /other4', [
    'filter_required' => 'yes',
    'http.method'     => 'DELETE',
]);

dd_trace_internal_fn('synchronous_flush');

// Capture ALL trace requests from the second flush before consuming them.
// The first flush's data was already consumed by waitForDataAndReplay() above, so only
// second-flush requests remain.  Poll until at least one trace request arrives, then
// collect everything that arrived in that batch.
$secondFlushTraces = [];
for ($i = 0; $i < 1000; $i++) {
    usleep(50000);  // 50 ms  (same interval as RequestReplayer::flushInterval)
    $reqs = $rr->replayAllRequests() ?? [];
    $traces = array_values(array_filter($reqs, function ($r) {
        return strpos($r['uri'] ?? '', 'traces') !== false;
    }));
    if (!empty($traces)) {
        $secondFlushTraces = $traces;
        break;
    }
}

// Extract span names from every trace request in this flush.
$namesInTraces = [];
foreach ($secondFlushTraces as $req) {
    $body = json_decode($req['body'] ?? '', true);
    if (!is_array($body)) continue;
    if (isset($body['chunks'])) {
        // v0.7 / sidecar format
        foreach ($body['chunks'] as $chunk) {
            foreach ($chunk['spans'] ?? [] as $span) {
                $n = $span['name'] ?? '';
                if ($n !== '') $namesInTraces[$n] = true;
            }
        }
    } else {
        // v0.4 format: array of traces, each trace is an array of spans
        foreach ($body as $trace) {
            if (!is_array($trace)) continue;
            foreach ($trace as $span) {
                $n = $span['name'] ?? '';
                if ($n !== '') $namesInTraces[$n] = true;
            }
        }
    }
}
// Diagnostic dump: snapshot the agent_info as seen extension-side. If the filter
// rules aren't present here, the sidecar never propagated them to the extension
// (regardless of what the agent_info /info endpoint returned).
try {
    $ai = dd_trace_internal_fn('get_agent_info');
    $aiSnap = is_array($ai) ? [
        'filter_tags'       => $ai['filter_tags']       ?? null,
        'filter_tags_regex' => $ai['filter_tags_regex'] ?? null,
        'ignore_resources'  => $ai['ignore_resources']  ?? null,
        'client_drop_p0s'   => $ai['client_drop_p0s']   ?? null,
    ] : $ai;
    echo "[FILTER-DIAG] agent_info_view: " . json_encode($aiSnap) . "\n";
} catch (\Throwable $e) {
    echo "[FILTER-DIAG] agent_info_view: (threw: " . $e->getMessage() . ")\n";
}

ksort($namesInTraces);
foreach (array_keys($namesInTraces) as $n) {
    echo "in traces: $n\n";
}

// Wait for a stats payload that contains our service.
// Stats from the second flush arrive a few seconds after waitForDataAndReplay() returns;
// use a matcher so we wait for the right payload rather than returning the first one
// (which contains the dummy span from the first flush).
$statsRequest = $rr->waitForStats(function ($request) {
    $payload = json_decode($request['body'], true);
    foreach ($payload['Stats'] ?? [] as $bucket) {
        foreach ($bucket['Stats'] ?? [] as $group) {
            if ($group['Service'] === 'filter-test-service') {
                return true;
            }
        }
    }
    return false;
});

// Print which operation names appear in stats (sorted for determinism).
// Only op.pass should survive all filters.
$payload = json_decode($statsRequest['body'], true);
$ops = [];
foreach ($payload['Stats'] as $bucket) {
    foreach ($bucket['Stats'] as $group) {
        if ($group['Service'] === 'filter-test-service') {
            $ops[] = $group['Name'];
        }
    }
}
sort($ops);

if (empty($ops)) {
    echo "ERROR: no filter-test-service stats groups found\n";
    var_dump($payload);
} else {
    foreach ($ops as $op) {
        echo "in stats: $op\n";
    }
}
?>
--EXPECTF--
[FILTER-DIAG] agent_info_view: %s
in traces: op.pass
in stats: op.pass
