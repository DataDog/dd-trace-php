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
// The test waits for the sidecar to receive these rules before creating spans.
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
DD_TRACE_AUTO_FLUSH_ENABLED=0
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

// 7. BLOCKED by ignore_resources — resource is empty so name "GET /healthcheck" is used instead and matches the pattern (as per normalization rules).
makeSpan('GET /healthcheck', '', [
    'filter_required' => 'yes',
    'http.method'     => 'GET',
]);

// Submit all cases together so a filtered span cannot arrive in a later batch
// after the trace assertion has already completed.
\DDTrace\flush();
dd_trace_internal_fn('synchronous_flush');

// A previous pass can leave an empty trace request behind. Wait for a payload
// containing spans, rather than stopping at the first request to /traces.
$traceRequest = $rr->waitForRequest(function ($request) {
    if (strpos($request['uri'] ?? '', 'traces') === false) {
        return false;
    }
    $body = json_decode($request['body'] ?? '', true);
    foreach ($body['chunks'] ?? $body ?? [] as $trace) {
        if (!empty($trace['spans'] ?? $trace)) {
            return true;
        }
    }
    return false;
});

// Extract span names from the payload containing all test cases.
$namesInTraces = [];
$body = json_decode($traceRequest['body'], true);
// v0.7 contains chunks of spans; v0.4 contains arrays of spans directly.
foreach ($body['chunks'] ?? $body as $trace) {
    foreach ($trace['spans'] ?? $trace as $span) {
        $n = $span['name'] ?? '';
        if ($n !== '') $namesInTraces[$n] = true;
    }
}
ksort($namesInTraces);
foreach (array_keys($namesInTraces) as $n) {
    echo "in traces: $n\n";
}

// Wait for a stats payload that contains our service.
// Trace and stats requests can arrive independently.
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
--EXPECT--
in traces: op.pass
in stats: op.pass
