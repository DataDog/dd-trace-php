--TEST--
No OPM is injected in outbound headers when no OPM was received and no local OPM
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_SAMPLING_PRIORITY=2
--FILE--
<?php

$headers = DDTrace\generate_distributed_tracing_headers(["datadog", "tracecontext"]);
echo "x-dd-opm: " . ($headers['x-dd-opm'] ?? '<none>') . "\n";
preg_match('/opm:([^;,]+)/', $headers['tracestate'] ?? '', $m);
echo "tracestate opm: " . ($m[1] ?? '<none>') . "\n";

?>
--EXPECT--
x-dd-opm: <none>
tracestate opm: <none>
