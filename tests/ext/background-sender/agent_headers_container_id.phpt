--TEST--
The container ID is sent via HTTP headers to the Agent
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There is no such concept like a container id on windows'); ?>
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_TRACE_BGS_ENABLED=1
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS=1
DD_TRACE_AGENT_FLUSH_INTERVAL=666
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_AUTO_FLUSH_ENABLED=1
--INI--
ddtrace.cgroup_file={PWD}/stubs/cgroup.docker
datadog.trace.agent_test_session_token=background-sender/agent_headers_container_id
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

datadog-container-id: 9d5b23edb1ba181e8910389a99906598d69ac9a0ead109ee55730cc416d95f7f
datadog-meta-lang: php

Done.
[ddtrace] [info] No finished traces to be sent to the agent
