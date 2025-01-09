--TEST--
Setting custom distributed header information with invalid propagated tags
--ENV--
HTTP_X_DATADOG_TRACE_ID=42
HTTP_X_DATADOG_PARENT_ID=10
HTTP_X_DATADOG_TAGS=_dd.p.custom_tag?!
--FILE--
<?php

print_r(DDTrace\SpanLink::fromHeaders([
    "traceparent" => "00-0000000000000000000000000000002a-0000000000000001-01",
    "tracestate" => "dd=foo:bar=baz",
]));

?>
--EXPECTF--
DDTrace\SpanLink Object
(
    [traceId] => 0000000000000000000000000000002a
    [spanId] => 0000000000000001
    [traceState] => dd=foo:bar=baz
    [attributes] => Array
        (
            [_dd.parent_id] => 0000000000000000
        )

)
