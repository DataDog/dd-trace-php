--TEST--
HTTP headers are sent to the Agent from the background sender
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS=1
DD_TRACE_AGENT_FLUSH_INTERVAL=666
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_AUTO_FLUSH_ENABLED=1
--INI--
datadog.trace.agent_test_session_token=background-sender/agent_headers
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

\DDTrace\start_span();
\DDTrace\close_span();

$rr = new RequestReplayer();

echo PHP_EOL;
$headers = $rr->replayHeaders([
    'content-type',
    'datadog-meta-lang',
    'datadog-meta-lang-interpreter',
    'datadog-meta-lang-version',
    'datadog-meta-tracer-version',
    'x-datadog-trace-count',
]);
foreach ($headers as $name => $value) {
    echo $name . ': ' . $value . PHP_EOL;
}
echo PHP_EOL;

echo 'Done.' . PHP_EOL;

?>
--EXPECTF--
[ddtrace] [info] Flushing trace of size 1 to send-queue for http://request-replayer:80

content-type: application/msgpack
datadog-meta-lang: php
datadog-meta-lang-interpreter: cli
datadog-meta-lang-version: %d.%d.%s
datadog-meta-tracer-version: %s
x-datadog-trace-count: 1

Done.
[ddtrace] [info] No finished traces to be sent to the agent
