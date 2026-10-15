--TEST--
Test baggage span tags default behavior
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_STYLE=baggage
--FILE--
<?php

DDTrace\consume_distributed_tracing_headers(function ($header) {
    return [
            "baggage" => "user.id=123,session.id=abc"
        ][$header] ?? null;
});
var_dump(DDTrace\start_span()->meta);

?>
--EXPECTF--
array(6) {
  ["baggage.user.id"]=>
  string(3) "123"
  ["baggage.session.id"]=>
  string(3) "abc"
  ["runtime-id"]=>
  string(36) "%s"
  ["service.name"]=>
  string(21) "baggage_span_tags.php"
  ["operation.name"]=>
  string(21) "baggage_span_tags.php"
  ["span.type"]=>
  string(3) "cli"
}