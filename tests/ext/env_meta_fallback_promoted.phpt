--TEST--
env/version from DD_TAGS (DD_ENV/DD_VERSION unset) still promote via the meta fallback (decision #5)
--DESCRIPTION--
When DD_ENV/DD_VERSION are unset, ddtrace_set_global_span_properties merges DD_TAGS "env"/"version"
into the span meta (property-first, meta otherwise). The V1 serializer promotes env/version from the
span property FIRST and falls back to that meta value, so a DD_TAGS-only env/version is not dropped.
Agent-free: asserts the introspection view that mirrors the V1 builder's promoted fields.
--INI--
datadog.trace.generate_root_span=0
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TAGS=env:staging,version:1.2.3
DD_ENV=
DD_VERSION=
--FILE--
<?php
$s = \DDTrace\start_span();
$s->name = "http.request";
$s->service = "web";
\DDTrace\close_span();

$spans = dd_trace_serialize_closed_spans();
$root = $spans[0];
// env/version promoted from the DD_TAGS-sourced meta fallback; consumed from meta once promoted.
echo "env=" . var_export($root["env"] ?? null, true) . "\n";
echo "version=" . var_export($root["version"] ?? null, true) . "\n";
echo "meta.env=" . var_export($root["meta"]["env"] ?? null, true) . "\n";
echo "meta.version=" . var_export($root["meta"]["version"] ?? null, true) . "\n";
?>
--EXPECT--
env='staging'
version='1.2.3'
meta.env=NULL
meta.version=NULL
