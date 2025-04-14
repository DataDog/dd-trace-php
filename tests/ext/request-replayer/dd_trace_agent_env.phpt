--TEST--
Assert that the default environment can be read from agent info
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php
if (PHP_VERSION_ID < 70400) die("skip: Before PHP 7.4, the skip-task would cause the sidecar to fetch the info already.");
if (PHP_VERSION_ID >= 80100) {
    echo "nocache\n";
}
$ctx = stream_context_create([
    'http' => [
        'method' => 'PUT',
        "header" => [
            "Content-Type: application/json",
            "X-Datadog-Test-Session-Token: dd_trace_agent_env",
        ],
        'content' => '{"config":{"default_env":"test_env"}}'
    ]
]);
file_get_contents("http://request-replayer/set-agent-info", false, $ctx);
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
--INI--
datadog.env=
datadog.trace.agent_test_session_token=dd_trace_agent_env
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer();

$span = \DDTrace\start_span();

// make sure sidecar keeps up with us
$start = microtime(true);
\DDTrace\start_trace_span();
\DDTrace\close_span();
$rr->waitForDataAndReplay();
usleep(floor((microtime(true) - $start) * 100000 / 5));

\DDTrace\close_span();
var_dump($span->env);

?>
--EXPECTF--
string(8) "test_env"
