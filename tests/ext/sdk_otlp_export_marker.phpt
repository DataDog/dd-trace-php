--TEST--
_dd.sdk.otlp_export marker is set to "false" on the payload, not on spans
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
for ($i = 0; $i < 2; $i++) {
    \DDTrace\start_trace_span()->name = "root_$i";
    \DDTrace\start_span()->name = "child_$i";
    \DDTrace\start_span()->name = "grandchild_$i";
    \DDTrace\close_span();
    \DDTrace\close_span();
    \DDTrace\close_span();
}

$payload = dd_trace_serialize_closed_spans();
echo "payload: ", var_export($payload['attributes']['_dd.sdk.otlp_export'] ?? null, true), "\n";
foreach ($payload['chunks'] as $chunk) {
    foreach ($chunk['spans'] as $span) {
        echo $span['name'], ": ", var_export($span['attributes']['_dd.sdk.otlp_export'] ?? null, true), "\n";
    }
}
echo count($payload['chunks']), " chunks\n";
?>
--EXPECT--
payload: 'false'
root_1: NULL
child_1: NULL
grandchild_1: NULL
root_0: NULL
child_0: NULL
grandchild_0: NULL
2 chunks
