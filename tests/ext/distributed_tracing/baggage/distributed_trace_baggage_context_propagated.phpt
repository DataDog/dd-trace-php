--TEST--
Test baggage round-trip propagation and multiple header handling
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_STYLE=baggage
--FILE--
<?php

// Round-trip Baggage Propagation
$span = DDTrace\start_span();
$span->baggage["userId"] = "Amélie";
$span->baggage["serverNode"] = "DF 28";
$span->baggage["isProduction"] = "false";

// Step 1: Inject into Headers
$headers = DDTrace\generate_distributed_tracing_headers();
var_dump($headers);

// Step 2: Extract into a New Span (Simulating Incoming Request)
DDTrace\consume_distributed_tracing_headers(function ($header) use ($headers) {
    return $headers[$header] ?? null;
});
$newSpan = DDTrace\start_span();
var_dump($newSpan->baggage);
DDTrace\close_span();
DDTrace\close_span();

// Multiple Headers Handling (Ensuring merging works)
DDTrace\consume_distributed_tracing_headers(function ($header) {
    return [
        "baggage" => "userId=JohnDoe,env=production"
    ][$header] ?? null;
});
$span = DDTrace\start_span();
$span->baggage["traceId"] = "abc123";
$span->baggage["service"] = "backend";

// Inject new baggage
$newHeaders = DDTrace\generate_distributed_tracing_headers();
var_dump($newHeaders);
DDTrace\close_span();

?>
--EXPECT--
array(1) {
  ["baggage"]=>
  string(56) "userId=Am%C3%A9lie,serverNode=DF%2028,isProduction=false"
}
array(3) {
  ["userId"]=>
  string(7) "Amélie"
  ["serverNode"]=>
  string(5) "DF 28"
  ["isProduction"]=>
  string(5) "false"
}
array(1) {
  ["baggage"]=>
  string(60) "userId=JohnDoe,env=production,traceId=abc123,service=backend"
}
