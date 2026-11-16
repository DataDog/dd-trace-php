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
            "baggage" => "user.id=Am%C3%A9lie,serverNode=DF%2028,isProduction=false,%22%2C%3B%5C%28%29%2F%3A%3C%3D%3E%3F%40%5B%5D%7B%7D=%22%2C%3B%5C"
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

?>
--EXPECT--
array(4) {
  ["user.id"]=>
  string(7) "Amélie"
  ["serverNode"]=>
  string(5) "DF 28"
  ["isProduction"]=>
  string(5) "false"
  ["",;\()/:<=>?@[]{}"]=>
  string(4) "",;\"
}
array(0) {
}
array(1) {
  ["baggage"]=>
  string(56) "userId=Am%C3%A9lie,serverNode=DF%2028,isProduction=false"
}