--TEST--
Test baggage header behavior with encoding and decoding
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_STYLE=baggage
--FILE--
<?php

// Testing Decoding Baggage
DDTrace\consume_distributed_tracing_headers(function ($header) {
    return [
            "baggage" => "user.id=Am%C3%A9lie,serverNode=DF%2028,isProduction=false"
        ][$header] ?? null;
});
var_dump(DDTrace\start_span()->baggage);
DDTrace\close_span();

// Testing Extracting Invalid Baggage (should be ignored)
DDTrace\consume_distributed_tracing_headers(function ($header) {
    return [
            "baggage" => "=Am%C3%A9lie,serverNode=DF%2028,isProduction=false"
        ][$header] ?? null;
});
var_dump(DDTrace\start_span()->baggage);
DDTrace\close_span();

// Testing Encoding
$span = DDTrace\start_span();
$span->baggage["userId"] = "Amélie";
$span->baggage["serverNode"] = "DF 28";
$span->baggage["isProduction"] = "false";
var_dump(DDTrace\generate_distributed_tracing_headers());
DDTrace\close_span();

// Testing Key Encoding - Invalid Keys (should not be included)
$span = DDTrace\start_span();
$span->baggage["invalid,key"] = "value"; // Invalid due to ","
$span->baggage["another@key"] = "valid"; // Invalid due to "@"
$span->baggage["valid_key"] = "encoded%20value"; // Valid key, value should be encoded
var_dump(DDTrace\generate_distributed_tracing_headers());
DDTrace\close_span();

?>
--EXPECT--
array(3) {
  ["user.id"]=>
  string(7) "Amélie"
  ["serverNode"]=>
  string(5) "DF 28"
  ["isProduction"]=>
  string(5) "false"
}
array(0) {
}
array(1) {
  ["baggage"]=>
  string(56) "userId=Am%C3%A9lie,serverNode=DF%2028,isProduction=false"
}
array(1) {
  ["baggage"]=>
  string(25) "valid_key=encoded%20value"
}
