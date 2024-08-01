--TEST--
HTTP headers are sent to the Agent from the background sender over an unix domain socket
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There are no unix sockets on Windows'); ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_URL=unix:///tmp/ddtrace-agent-test.socket
DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS=1
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_AUTO_FLUSH_ENABLED=1
--INI--
datadog.trace.agent_test_session_token=background-sender/agent_headers_unix_domain_socket
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

RequestReplayer::launchUnixProxy(str_replace("unix://", "", getenv("DD_TRACE_AGENT_URL")));

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
--CLEAN--
<?php
@unlink("/tmp/ddtrace-agent-test.socket");
?>
--EXPECTF--
content-type: application/msgpack
datadog-meta-lang: php
datadog-meta-lang-interpreter: cli
datadog-meta-lang-version: %d.%d.%s
datadog-meta-tracer-version: %s
x-datadog-trace-count: 1

Done.
