--TEST--
Test baggage span tags empty config
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_STYLE=baggage
DD_TRACE_BAGGAGE_TAG_KEYS=""
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers(function ($header) {
    return [
            "baggage" => "user.id=123,session.id=abc,region=us-east1,account.id=987"
        ][$header] ?? null;
});
var_dump(DDTrace\start_span()->meta);

?>
--EXPECTF--
array(1) {
  ["runtime-id"]=>
  string(36) "%s"
}