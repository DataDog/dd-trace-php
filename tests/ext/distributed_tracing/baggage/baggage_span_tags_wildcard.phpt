--TEST--
Test baggage span tags wildcard
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_STYLE=baggage
DD_TRACE_BAGGAGE_TAG_KEYS=*
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers(function ($header) {
    return [
            "baggage" => "user.id=123,session.id=abc,region=us-east1,language=php"
        ][$header] ?? null;
});
var_dump(DDTrace\start_span()->meta);

?>
--EXPECTF--
array(5) {
  ["baggage.user.id"]=>
  string(3) "123"
  ["baggage.session.id"]=>
  string(3) "abc"
  ["baggage.region"]=>
  string(8) "us-east1"
  ["baggage.language"]=>
  string(3) "php"
  ["runtime-id"]=>
  string(36) "%s"
}