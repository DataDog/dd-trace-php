--TEST--
OPM received via x-dd-opm Datadog header is propagated in outbound headers when no local OPM
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_SAMPLING_PRIORITY=2
HTTP_X_DD_OPM=my-org-opm
--FILE--
<?php

$headers = DDTrace\generate_distributed_tracing_headers(["datadog", "tracecontext"]);
echo "x-dd-opm: " . ($headers['x-dd-opm'] ?? '<none>') . "\n";
preg_match('/opm:([^;,]+)/', $headers['tracestate'] ?? '', $m);
echo "tracestate opm: " . ($m[1] ?? '<none>') . "\n";

?>
--EXPECT--
x-dd-opm: my-org-opm
tracestate opm: my-org-opm
