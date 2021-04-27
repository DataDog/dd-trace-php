--TEST--
background sender happy path
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
