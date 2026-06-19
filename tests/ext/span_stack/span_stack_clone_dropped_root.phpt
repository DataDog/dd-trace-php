--TEST--
Cloning a span stack must not leave a dangling root_span alias when the root span is dropped (#3943)
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=1
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php

// A populated baggage array on the root span is what turned the dangling root_span read into the
// observed GC_ADDREF crash; keep it so this exercises the exact path.
\DDTrace\root_span()->baggage["k"] = "v";

// Open a sub-trace, capture its stack, then close that sub-trace's own root span. The sub-stack now
// has no active span and no root span of its own.
\DDTrace\start_trace_span();
$sub = \DDTrace\active_stack();
\DDTrace\close_span();

// Cloning must not copy the parent stack's root_span verbatim as a non-owning alias: the clone holds
// no reference to that root span, so the pointer would dangle once it is dropped below.
$clone = clone $sub;

// Drop the (auto) root span via the runtime config change.
ini_set("datadog.trace.generate_root_span", "0");

// Before the fix, starting a new trace whose parent stack is the clone dereferenced the freed root
// span in ddtrace_set_root_span_properties -> ddtrace_inherit_span_properties (heap-use-after-free).
\DDTrace\switch_stack($clone);
\DDTrace\start_trace_span()->name = "after clone";

echo "completed without use-after-free\n";
var_dump(\DDTrace\active_span()->name);

?>
--EXPECT--
completed without use-after-free
string(11) "after clone"
