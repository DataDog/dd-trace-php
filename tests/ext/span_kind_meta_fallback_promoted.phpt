--TEST--
Root span with only meta["span.kind"] (no property) still promotes span_kind (R2 meta fallback)
--DESCRIPTION--
Entrypoint/integration spans set span.kind via meta (serializer writes meta["span.kind"]="server"
for HTTP roots), not via the OTel property. The V1-only serializer must fall back to the meta value
when property_span_kind is unset, so the promoted top-level span_kind is still emitted. Agent-free:
asserts the introspection view that mirrors the V1 builder's promoted fields.
--INI--
datadog.trace.generate_root_span=0
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
--FILE--
<?php
$s = \DDTrace\start_span();
$s->name = "http.request";
$s->service = "web";
$s->meta["span.kind"] = "server"; // meta only, no $s->kind property
\DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans();
$root = $spans[0];
// server == OTEL SpanKind 2; promoted from meta fallback (dd_span_kind_meta_to_otel). The serializer
// consumes meta["span.kind"] once promoted, so the introspection (pre-encode builder) view no longer
// carries it in meta; the wire decoders re-materialise span.kind in meta downstream.
echo "span_kind=" . var_export($root["span_kind"] ?? null, true) . "\n";
echo "meta.span.kind=" . var_export($root["meta"]["span.kind"] ?? null, true) . "\n";
?>
--EXPECT--
span_kind=2
meta.span.kind=NULL
