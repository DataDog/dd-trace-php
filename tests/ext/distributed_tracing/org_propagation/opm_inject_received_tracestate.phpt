--TEST--
OPM received via W3C tracestate opm: key is propagated in outbound headers when no local OPM
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
HTTP_TRACEPARENT=00-0000000000000000000000000000002a-000000000000000a-01
HTTP_TRACESTATE=dd=s:2;opm:ts-org-opm
--FILE--
<?php

$headers = DDTrace\generate_distributed_tracing_headers(["datadog", "tracecontext"]);
echo "x-dd-opm: " . ($headers['x-dd-opm'] ?? '<none>') . "\n";
preg_match('/opm:([^;,]+)/', $headers['tracestate'] ?? '', $m);
echo "tracestate opm: " . ($m[1] ?? '<none>') . "\n";

?>
--EXPECT--
x-dd-opm: ts-org-opm
tracestate opm: ts-org-opm
