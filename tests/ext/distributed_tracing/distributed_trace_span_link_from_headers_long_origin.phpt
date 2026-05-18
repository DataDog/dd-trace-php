--TEST--
SpanLink::fromHeaders handles long propagated origins
--DESCRIPTION--
Formatting a propagated origin into tracestate sanitizes the origin after
appending it to a smart_str. The sanitizer must not keep a pointer into the
smart_str across the append, because a long origin can reallocate the buffer
before sanitization.
--INI--
datadog.trace.enabled=1
datadog.trace.generate_root_span=0
datadog.trace.propagation_style_extract=datadog
--FILE--
<?php

$origin = str_repeat("=", 1000);
$link = DDTrace\SpanLink::fromHeaders([
    "x-datadog-trace-id" => "42",
    "x-datadog-parent-id" => "1",
    "x-datadog-origin" => $origin,
]);

$traceState = $link->traceState;
$traceStatePrefix = "dd=o:";
$formattedOrigin = substr($traceState, strlen($traceStatePrefix));

var_dump(substr($traceState, 0, strlen($traceStatePrefix)));
var_dump(substr_count($traceState, "="));
var_dump($formattedOrigin === str_repeat("~", strlen($formattedOrigin)));
var_dump(strlen($formattedOrigin) > 256);

?>
--EXPECT--
string(5) "dd=o:"
int(1)
bool(true)
bool(true)
