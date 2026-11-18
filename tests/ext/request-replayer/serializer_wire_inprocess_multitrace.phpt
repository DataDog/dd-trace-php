--TEST--
In-process sender frames every trace of a multi-trace flush (per-trace coms group id)
--SKIPIF--
<?php
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: the in-process sender is not available on Windows');
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
// Pre-seed a NON-v1 /info for this session token and warm the connection in a
// separate process, mirroring the sibling v0.4 wire test. Also doubles as the
// request-replayer reachability probe.
$ctx = stream_context_create(['http' => [
    'method' => 'PUT',
    'header' => ["Content-Type: application/json", "X-Datadog-Test-Session-Token: inproc_multitrace"],
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
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
--INI--
datadog.trace.agent_test_session_token=inproc_multitrace
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';
$rr = new RequestReplayer();
$rr->clearDumpedData();

dd_trace_internal_fn('await_agent_info');

// Three independent root traces closed before a single flush. Each closed span
// stack becomes its own v0.4 trace; every trace must reach the agent, framed
// as an outer msgpack array of the exact trace count it carries.
for ($i = 0; $i < 3; $i++) {
    $s = \DDTrace\start_trace_span();
    $s->name = "root$i";
    $s->service = "svc";
    \DDTrace\close_span();
}
// One flush serializes all three closed traces together, exercising the
// per-trace coms group-id path; synchronous_flush then pushes the buffer.
\DDTrace\flush();
dd_trace_internal_fn("synchronous_flush");

// The background sender may rotate its stack between the flush's per-trace
// writes and deliver the traces in several requests; each request must frame
// the traces it carries correctly, and all three traces must arrive exactly
// once across the requests.
$traceCountHeader = 0;
$traceCount = 0;
$names = array();
$uri = null;
for ($i = 0; $i < 500; $i++) {
    $requests = $rr->replayAllRequests();
    if (is_array($requests)) {
        foreach ($requests as $request) {
            if (strpos($request["uri"], "/v0.4/traces") === false) continue;
            $requestTraces = json_decode($request["body"], true);
            $requestHeaderCount = (int)$request["headers"]["X-Datadog-Trace-Count"];
            if ($requestHeaderCount !== count($requestTraces)) {
                throw new Exception("a request advertised $requestHeaderCount trace(s) but carried " . count($requestTraces));
            }
            if ($uri === null) $uri = $request["uri"];
            $traceCountHeader += $requestHeaderCount;
            $traceCount += count($requestTraces);
            foreach ($requestTraces as $trace) $names[] = $trace[0]["name"];
        }
    }
    if (count(array_unique($names)) === 3) break;
    usleep($rr->flushInterval);
}
$distinct = array_values(array_unique($names));
sort($distinct);
if ($distinct !== array("root0", "root1", "root2")) {
    throw new Exception("expected root0,root1,root2 across the delivered requests, saw " . implode(",", $distinct));
}
if (count($names) !== 3) throw new Exception("duplicate traces delivered: " . implode(",", $names));
sort($names);
echo "uri=$uri\n";
echo "trace_count_header=$traceCountHeader\n";
echo "trace_count=$traceCount\n";
echo "names=" . implode(",", $names) . "\n";
?>
--EXPECTF--
[ddtrace] [info] [%d] Flushing 3 v0.4 trace(s) to send-queue for http://request-replayer:80
uri=/v0.4/traces
trace_count_header=3
trace_count=3
names=root0,root1,root2
[ddtrace] [info] [%d] No finished traces to be sent to the agent
