--TEST--
HTTP Agent headers are ignored from userland
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

$headersToIgnore = ['This-Should-Be: ignored'];
// payload = [[]]
$payload = "\x91\x90";
var_dump(dd_trace_send_traces_via_thread(1, $headersToIgnore, $payload));

$rr = new RequestReplayer();
$rr->waitForFlush();

echo PHP_EOL;
$headers = $rr->replayHeaders([
    'Datadog-Meta-Lang',
    'This-Should-Be',
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

Datadog-Meta-Lang: php

Done.
No finished traces to be sent to the agent
