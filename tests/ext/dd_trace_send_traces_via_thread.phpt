--TEST--
Test the background sender does not leak
--SKIPIF--
<?php if (!getenv("DD_AGENT_HOST")) die("skip test if agent host is not set"); ?>
--ENV--
--FILE--
<?php

$host = getenv("DD_AGENT_HOST");
$port = getenv("DD_TRACE_AGENT_PORT");
if ($port === false) {
    $port = '8126';
}
$url = "http://{$host}:{$port}/0.4/traces";
$headers = [
    'Datadog-Meta-Lang' => 'php',
];

// payload = []
$payload = "\x90";


dd_trace_send_traces_via_thread($url, $headers, $payload);

echo "Done.";
?>
--EXPECT--
Done.
