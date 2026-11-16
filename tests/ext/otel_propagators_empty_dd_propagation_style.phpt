--TEST--
OTEL_PROPAGATORS fallback uses correct buffer when DD_TRACE_PROPAGATION_STYLE fails to decode
--DESCRIPTION--
Regression test for a heap buffer overflow in zai_config_find_and_set_value.

When DD_TRACE_PROPAGATION_STYLE is set to a value that fails to decode (e.g. a
bare comma, which is all-separators and produces an empty set), the sys env cache
stores a small persistent allocation for the raw value. The decode failure leaves
value.len == 0, so the OTEL fallback is triggered. On the unfixed code, buf.ptr
is still aliased to that small allocation; ddtrace_conf_otel_propagators then
writes up to 30 bytes into it via memcpy, causing a heap-buffer-overflow.

Under ASAN, the unfixed code crashes the process during MINIT before any PHP
code runs; the test captures no output and fails. The fix uses a fresh 32 KB
buffer for every fallback call, preventing the overflow.

Note: an empty string (DD_TRACE_PROPAGATION_STYLE=) cannot be used here because
PHP's proc_open silently drops env-array entries with empty-string values. A bare
comma (",") is non-empty so proc_open passes it, but SET_LOWERCASE decode rejects
it (all-separator input produces zero set elements), triggering the same
fallback+overflow path.
--SKIPIF--
<?php if (!extension_loaded('ddtrace')) die('skip: ddtrace extension required'); ?>
--ENV--
DD_TRACE_PROPAGATION_STYLE=,
OTEL_PROPAGATORS=tracecontext,b3
--FILE--
<?php
var_dump(ini_get("datadog.trace.propagation_style"));
?>
--EXPECT--
string(29) "tracecontext,b3 single header"
