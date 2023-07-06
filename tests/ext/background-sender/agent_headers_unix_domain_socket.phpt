--TEST--
HTTP headers are sent to the Agent from the background sender over an unix domain socket
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_URL=unix:///tmp/ddtrace-agent-test.socket
DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS=1
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_TELEMETRY_ENABLED=0
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

RequestReplayer::launchUnixProxy(str_replace("unix://", "", getenv("DD_TRACE_AGENT_URL")));

// payload = [[]]
$payload = "\x91\x90";
var_dump(dd_trace_send_traces_via_thread(1, [], $payload));

$rr = new RequestReplayer();
$rr->waitForFlush();

echo PHP_EOL;
$headers = $rr->replayHeaders([
    'Content-Type',
    'Datadog-Meta-Lang',
    'Datadog-Meta-Lang-Interpreter',
    'Datadog-Meta-Lang-Version',
    'Datadog-Meta-Tracer-Version',
    'X-Datadog-Trace-Count',
]);
foreach ($headers as $name => $value) {
    echo $name . ': ' . $value . PHP_EOL;
}
echo PHP_EOL;

echo 'Done.' . PHP_EOL;

?>
--CLEAN--
<?php
@unlink("/tmp/ddtrace-agent-test.socket");
?>
--EXPECTF--
bool(true)

Content-Type: application/msgpack
Datadog-Meta-Lang: php
Datadog-Meta-Lang-Interpreter: cli
Datadog-Meta-Lang-Version: %d.%d.%s
Datadog-Meta-Tracer-Version: %s
X-Datadog-Trace-Count: 1

Done.
