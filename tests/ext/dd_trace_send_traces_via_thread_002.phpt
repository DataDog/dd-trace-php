--TEST--
background sender should reject msgpack array prefix that does not match expected number of traces
--FILE--
<?php

$headers = [
    'Datadog-Meta-Lang' => 'php',
];

// payload = []
$payload = "\x90";

var_dump(dd_trace_send_traces_via_thread(1, $headers, $payload));

echo "Done.";
?>
--EXPECT--
bool(false)
Done.
