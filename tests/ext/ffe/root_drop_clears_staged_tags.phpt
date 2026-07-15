--TEST--
FFE span enrichment: dropping a root span clears staged tags so they cannot leak onto the next root
--SKIPIF--
<?php
if (getenv('PHP_PEAR_RUNTESTS') === '1') die("skip: pecl run-tests does not support %r");
?>
--INI--
datadog.trace.generate_root_span=0
datadog.trace.auto_flush_enabled=1
--ENV--
DD_EXPERIMENTAL_FLAGGING_PROVIDER_SPAN_ENRICHMENT_ENABLED=1
--FILE--
<?php
// Simulate a flag having been evaluated (and staged) under a root that is
// later dropped (e.g. via \DDTrace\try_drop_span()) rather than closed
// normally. A dropped root never reaches the native close-span flush, so
// pre-fix these staged tags would still be sitting in the request-global
// slots afterward. Mirrors the proven try_drop_span_root.phpt pattern (same
// INI/refcount shape) so try_drop_span() actually takes the real-drop branch
// in tracer/span.c rather than the refcount>2 safe-reject-via-normal-close
// fallback.
\DDTrace\Internal\set_ffe_span_enrichment_tags("ZAgUAg==", null, null);

$extraRef = $span = \DDTrace\start_span();
$span->onClose[] = function ($span) {
    var_dump(\DDTrace\try_drop_span($span));
};
\DDTrace\close_span();

// A second, unrelated root opens and closes normally. Pre-fix, the tags
// staged for the dropped root above would still be sitting in the native
// globals and would get flushed onto THIS span instead of being discarded.
\DDTrace\start_span();
\DDTrace\close_span();

foreach (dd_trace_serialize_closed_spans() as $span) {
    var_dump(array_key_exists("ffe_flags_enc", $span["meta"]));
}
?>
--EXPECT--
bool(true)
bool(false)
