--TEST--
#[DDTrace\Trace] tags keep their meta strings on the v0.4 wire (array leaves included)
--SKIPIF--
<?php
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: the in-process sender is not available on Windows');
if (PHP_VERSION_ID < 80000) die('skip: attributes require PHP 8');
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
// Pre-seed a NON-v1 /info (no /v1.0/traces) for this session token so the in-process
// agent-info reader negotiates the v0.4 downgrade. Done in SKIPIF (separate process)
// so the reader never observes the default v1-advertising /info.
$ctx = stream_context_create(['http' => [
    'method' => 'PUT',
    'header' => ["Content-Type: application/json", "X-Datadog-Test-Session-Token: traced_attribute_tags_v04"],
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
datadog.trace.agent_test_session_token=traced_attribute_tags_v04
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();

dd_trace_internal_fn('await_agent_info');

#[DDTrace\Trace(name: "traced", tags: ["i" => 7, "arr" => [1, true, 2.5, null], "map" => ["k" => 3, "e" => []]])]
function traced() {}

$s = \DDTrace\start_span();
$s->name = "root";
traced();
\DDTrace\close_span();
dd_trace_internal_fn("synchronous_flush");

$req = $rr->waitForRequest(function ($r) { return strpos($r["uri"], "traces") !== false; });
foreach (json_decode($req["body"], true)[0] as $span) {
    if ($span["name"] !== "traced") {
        continue;
    }
    foreach (['meta', 'metrics'] as $bucket) {
        $tags = isset($span[$bucket]) ? $span[$bucket] : [];
        ksort($tags);
        foreach ($tags as $key => $value) {
            if (preg_match('/^(i|arr|map)\b/', $key)) {
                echo "$bucket.$key=", var_export($value, true), "\n";
            }
        }
    }
}
?>
--EXPECTF--
[ddtrace] [info] [%d] Flushing %d v0.4 trace(s) to send-queue for http://request-replayer:80
meta.arr.0='1'
meta.arr.1='true'
meta.arr.2='2.5'
meta.arr.3='null'
meta.i='7'
meta.map.e=''
meta.map.k='3'
[ddtrace] [info] [%d] No finished traces to be sent to the agent
