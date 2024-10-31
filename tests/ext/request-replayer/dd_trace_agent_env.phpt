--TEST--
DDTrace\ExceptionSpanEvent serialization with overridden attributes
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php
echo "nocache\n";
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

$span = \DDTrace\start_span();
usleep(500000);
\DDTrace\close_span();
var_dump($span->env);

?>
--EXPECTF--
string(8) "test_env"
