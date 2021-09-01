--TEST--
The Fargate 1.4+ container ID is sent via HTTP headers to the Agent
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--INI--
ddtrace.cgroup_file={PWD}/stubs/cgroup.fargate.1.4
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

$headers = $rr->replayHeaders();

echo PHP_EOL;

echo 'Datadog-Container-Id: ' . $headers['Datadog-Container-Id'] . PHP_EOL;
echo 'Datadog-Meta-Lang: ' . $headers['Datadog-Meta-Lang'] . PHP_EOL;

echo PHP_EOL;

echo 'Done.' . PHP_EOL;

if (PHP_VERSION_ID < 70000) {
    echo "No finished traces to be sent to the agent", PHP_EOL;
}

?>
--EXPECTF--
bool(true)

Datadog-Container-Id: 34dc0b5e626f2c5c4c5170e34b10e765-1234567890
Datadog-Meta-Lang: php

Done.
No finished traces to be sent to the agent