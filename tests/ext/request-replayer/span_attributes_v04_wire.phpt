--TEST--
Typed SpanData::$attributes on the v0.4 wire: numbers go to metrics, bools and strings to meta, nested values flatten
--SKIPIF--
<?php
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: the in-process sender is not available on Windows');
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
// Pre-seed a NON-v1 /info (no /v1.0/traces) for this session token so the in-process
// agent-info reader negotiates the v0.4 downgrade. Done in SKIPIF (separate process)
// so the reader never observes the default v1-advertising /info.
$ctx = stream_context_create(['http' => [
    'method' => 'PUT',
    'header' => ["Content-Type: application/json", "X-Datadog-Test-Session-Token: span_attributes_v04"],
    'content' => json_encode(["endpoints" => ["/v0.4/traces", "/v0.6/stats", "/v0.7/config"], "client_drop_p0s" => false, "version" => "7.66.0"]),
]]);
if (@file_get_contents("http://request-replayer/set-agent-info", false, $ctx) === false) {
    die("skip: request-replayer not reachable");
}
?>
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
--INI--
datadog.trace.agent_test_session_token=span_attributes_v04
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();

dd_trace_internal_fn('await_agent_info');

$s = \DDTrace\start_span();
$s->name = "root";
$s->attributes = ['int' => 42, 'float' => 1.5, 'bool' => true, 'false' => false, 'string' => 's', 'list' => [1, 'two'], 'map' => ['k' => 'v']];
$s->meta['string'] = 'meta loses';
$s->meta['meta_only'] = 'm';
$s->metrics['int'] = 7;
\DDTrace\close_span();
dd_trace_internal_fn("synchronous_flush");

$req = $rr->waitForRequest(function ($r) { return strpos($r["uri"], "traces") !== false; });
$span = json_decode($req["body"], true)[0][0];
echo "uri=" . $req["uri"] . "\n";
foreach (['meta', 'metrics'] as $bucket) {
    foreach (['int', 'float', 'bool', 'false', 'string', 'list.0', 'list.1', 'map.k', 'meta_only'] as $key) {
        if (isset($span[$bucket][$key])) {
            echo "$bucket.$key=", var_export($span[$bucket][$key], true), "\n";
        }
    }
}
?>
--EXPECTF--
[ddtrace] [info] [%d] Flushing %d v0.4 trace(s) to send-queue for http://request-replayer:80
uri=/v0.4/traces
meta.bool='true'
meta.false='false'
meta.string='s'
meta.list.1='two'
meta.map.k='v'
meta.meta_only='m'
metrics.int=42
metrics.float=1.5
metrics.list.0=1
[ddtrace] [info] [%d] No finished traces to be sent to the agent
