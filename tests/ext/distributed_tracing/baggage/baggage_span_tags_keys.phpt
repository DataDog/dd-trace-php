--TEST--
Test baggage span tags specifying keys
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_STYLE=baggage
DD_TRACE_BAGGAGE_TAG_KEYS=region
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers(function ($header) {
    return [
            "baggage" => "user.id=123,session.id=abc,region=us-east1"
        ][$header] ?? null;
});
var_dump(DDTrace\start_span()->meta);

?>
--EXPECTF--
array(2) {
  ["baggage.region"]=>
  string(8) "us-east1"
  ["runtime-id"]=>
  string(36) "%s"
}