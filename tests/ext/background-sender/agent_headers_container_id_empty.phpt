--TEST--
An empty container ID is not sent via HTTP headers to the Agent
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_BGS_ENABLED=1
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS=1
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_AUTO_FLUSH_ENABLED=1
--INI--
ddtrace.cgroup_file={PWD}/stubs/cgroup.empty
datadog.trace.agent_test_session_token=background-sender/agent_headers_container_id_empty
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

\DDTrace\start_span();
\DDTrace\close_span();

$rr = new RequestReplayer();

echo PHP_EOL;
$headers = $rr->replayHeaders([
    'datadog-container-id',
    'datadog-meta-lang',
]);
foreach ($headers as $name => $value) {
    echo $name . ': ' . $value . PHP_EOL;
}
echo PHP_EOL;

echo 'Done.' . PHP_EOL;

?>
--EXPECTF--
[ddtrace] [info] Flushing trace of size 1 to send-queue for http://request-replayer:80

datadog-meta-lang: php

Done.
[ddtrace] [info] No finished traces to be sent to the agent
