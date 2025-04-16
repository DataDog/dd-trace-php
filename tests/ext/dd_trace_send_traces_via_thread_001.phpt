--TEST--
background sender happy path
--SKIPIF--
<?php if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: There is no background sender on Windows'); ?>
--ENV--
DD_TRACE_SIDECAR_TRACE_SENDER=0
--FILE--
<?php

$headers = [
    'Datadog-Meta-Lang' => 'php',
];

// payload = [[]]
$payload = "\x91\x90";

var_dump(dd_trace_send_traces_via_thread(1, $headers, $payload));

echo "Done.";
?>
--EXPECT--
bool(true)
Done.
