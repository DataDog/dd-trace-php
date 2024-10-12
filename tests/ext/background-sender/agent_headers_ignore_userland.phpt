--TEST--
HTTP Agent headers are ignored from userland
--SKIPIF--
<?php
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
if (dd_trace_env_config('DD_TRACE_SIDECAR_TRACE_SENDER')) die("skip: background-sender only test");
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There is no background sender on Windows');
?>
--ENV--
DD_TRACE_LOG_LEVEL=info,startup=off
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS=1
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
--INI--
datadog.trace.agent_test_session_token=background-sender/agent_headers_ignore_userland
--FILE--
<?php
include __DIR__ . '/../includes/request_replayer.inc';

$headersToIgnore = ['this-should-be: ignored'];
// payload = [[]]
$payload = "\x91\x90";
var_dump(dd_trace_send_traces_via_thread(1, $headersToIgnore, $payload));

$rr = new RequestReplayer();

echo PHP_EOL;
$headers = $rr->replayHeaders([
    'datadog-meta-lang',
    'this-should-be',
]);
foreach ($headers as $name => $value) {
    echo $name . ': ' . $value . PHP_EOL;
}
echo PHP_EOL;

echo 'Done.' . PHP_EOL;

?>
--EXPECTF--
bool(true)

datadog-meta-lang: php

Done.
[ddtrace] [info] No finished traces to be sent to the agent
