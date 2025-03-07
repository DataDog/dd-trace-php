--TEST--
Test baggage header behavior when configured by itself
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers(function ($header) {
    return [
            "baggage" => "user.id=123,session.id=abc"
        ][$header] ?? null;
});
var_dump(DDTrace\generate_distributed_tracing_headers());

?>
--EXPECT--
array(1) {
  ["baggage"]=>
  string(26) "user.id=123,session.id=abc"
}
