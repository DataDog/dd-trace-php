--TEST--
Test baggage span tags wildcard
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_PROPAGATION_STYLE=baggage
DD_TRACE_BAGGAGE_TAG_KEYS=*
--FILE--
<?php

$baggage = sprintf(
    "user.id=%s,session.id=%s,region=%s,language=%s",
    "123", "abc", "us-east1", "php"
);

DDTrace\consume_distributed_tracing_headers(function ($header) use ($baggage) {
    return [
        "baggage" => $baggage,
    ][$header] ?? null;
});

$root = DDTrace\start_span();
// Force an early destruction of the baggage table to surface any refcounting/ownership issues where
// baggage values are also stored in span meta without a separate reference.
$root->baggage = [];
$meta = $root->meta;
ksort($meta);
var_dump($meta);

?>
--EXPECTF--
array(5) {
  ["baggage.language"]=>
  string(3) "php"
  ["baggage.region"]=>
  string(8) "us-east1"
  ["baggage.session.id"]=>
  string(3) "abc"
  ["baggage.user.id"]=>
  string(3) "123"
  ["runtime-id"]=>
  string(36) "%s"
}
