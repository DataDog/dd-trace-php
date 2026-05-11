--TEST--
SpanLink::fromHeaders handles long propagated origins
--DESCRIPTION--
Formatting a propagated origin into tracestate sanitizes the origin after
appending it to a smart_str. The sanitizer must not keep a pointer into the
smart_str across the append, because a long origin can reallocate the buffer
before sanitization.
--ENV--
DD_TRACE_PROPAGATION_STYLE_EXTRACT=datadog
--FILE--
<?php

$origin = str_repeat("=", 1000);
$link = DDTrace\SpanLink::fromHeaders([
    "x-datadog-trace-id" => "42",
    "x-datadog-parent-id" => "1",
    "x-datadog-origin" => $origin,
]);

$traceState = $link->traceState;

var_dump(strlen($traceState));
var_dump(substr_count($traceState, "="));
var_dump(substr_count($traceState, "~"));
var_dump($traceState === "dd=o:" . str_repeat("~", strlen($origin)));

?>
--EXPECT--
int(1005)
int(1)
int(1000)
bool(true)
