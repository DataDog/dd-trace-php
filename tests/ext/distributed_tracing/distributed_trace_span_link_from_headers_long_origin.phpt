--TEST--
SpanLink::fromHeaders handles long tracestate origins
--DESCRIPTION--
Formatting a propagated tracestate origin sanitizes the origin after appending
it to a smart_str. The sanitizer must not keep a pointer into the smart_str
across the append, because a long origin can reallocate the buffer before
sanitization.
--FILE--
<?php

$origin = str_repeat("~", 1000);
$link = DDTrace\SpanLink::fromHeaders([
    "traceparent" => "00-0000000000000000000000000000002a-0000000000000001-01",
    "tracestate" => "dd=o:" . $origin,
]);

$traceState = $link->traceState;

var_dump(strlen($traceState));
var_dump(substr_count($traceState, "="));
var_dump(substr_count($traceState, "~"));
var_dump($traceState === "dd=o:" . $origin);

?>
--EXPECT--
int(1005)
int(1)
int(1000)
bool(true)
