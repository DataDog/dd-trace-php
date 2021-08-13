--TEST--
HTTP headers are sent to the Agent from the background sender
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_TRACE_DEBUG=1
DD_TRACE_BGS_ENABLED=1
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS=1
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

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

if (PHP_VERSION_ID < 70000) {
    echo "No finished traces to be sent to the agent", PHP_EOL;
}

?>
--EXPECTF--
bool(true)

Content-Type: application/msgpack
Datadog-Meta-Lang: php
Datadog-Meta-Lang-Interpreter: cli
Datadog-Meta-Lang-Version: %d.%d.%d
Datadog-Meta-Tracer-Version: %s
X-Datadog-Trace-Count: 1

Done.
No finished traces to be sent to the agent
