--TEST--
Client-side stats read the attributes that by-ref uses of $meta leave as references
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
            'X-Datadog-Test-Session-Token: client_side_stats_attribute_references',
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
datadog.version=1.2.3-refs
datadog.trace.agent_test_session_token=client_side_stats_attribute_references
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

function set_by_ref(&$var, $value) {
    $var = $value;
}

$rr = new RequestReplayer();

dd_trace_internal_fn('await_agent_info');

$root = \DDTrace\start_trace_span();
$root->name = "web.request";
$root->resource = "POST /users";
$root->service = "refs-service";
$root->meta['span.kind'] = 'server';
set_by_ref($root->meta['http.status_code'], '404');
$method = &$root->meta['http.method'];
$method = 'POST';
unset($method);
$root->meta['http.route'] = '/users/{id}';
$route = &$root->meta['http.route'];
unset($route);
set_by_ref($root->meta['grpc.code'], '5');
$root->metrics['rpc.grpc.status_code'] = 3; // a numeric tag: the string one wins

$client = \DDTrace\start_span();
$client->name = "db.query";
$client->resource = "SELECT 1";
$client->service = "refs-db";
$client->meta['span.kind'] = 'client';
set_by_ref($client->meta['db.hostname'], 'my-db-host');
\DDTrace\close_span();

\DDTrace\close_span();

dd_trace_internal_fn('synchronous_flush');
$rr->waitForDataAndReplay();

$groups = [];
$rr->waitForStats(function ($request) use (&$groups) {
    foreach (json_decode($request['body'], true)['Stats'] ?? [] as $bucket) {
        foreach ($bucket['Stats'] ?? [] as $group) {
            $groups[$group['Service']] = $group;
        }
    }
    return isset($groups['refs-service'], $groups['refs-db']);
});

$web = $groups['refs-service'];
echo "HTTPStatusCode: ", json_encode($web['HTTPStatusCode'] ?? null), "\n";
echo "HTTPMethod: ", json_encode($web['HTTPMethod'] ?? null), "\n";
echo "HTTPEndpoint: ", json_encode($web['HTTPEndpoint'] ?? null, JSON_UNESCAPED_SLASHES), "\n";
echo "GRPCStatusCode: ", json_encode($web['GRPCStatusCode'] ?? null), "\n";
echo "PeerTags: ", json_encode($groups['refs-db']['PeerTags'] ?? null), "\n";

?>
--EXPECT--
HTTPStatusCode: 404
HTTPMethod: "POST"
HTTPEndpoint: "/users/{id}"
GRPCStatusCode: "5"
PeerTags: ["db.hostname:my-db-host"]
