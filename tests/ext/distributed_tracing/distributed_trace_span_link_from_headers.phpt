--TEST--
Setting custom distributed header information
--ENV--
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_ORIGIN=datadog
HTTP_X_DATADOG_TAGS=_dd.p.custom_tag=inherited,_dd.p.second_tag=bar
--FILE--
<?php

print_r(DDTrace\SpanLink::fromHeaders(DDTrace\generate_distributed_tracing_headers()));

?>
--EXPECTF--
DDTrace\SpanLink Object
(
    [traceId] => 0000000000000000000000000000002a
    [spanId] => %s
    [traceState] => dd=o:datadog;t.custom_tag:inherited;t.second_tag:bar
    [attributes] => Array
        (
            [_dd.p.custom_tag] => inherited
            [_dd.p.second_tag] => bar
        )

)
