--TEST--
\DDTrace\start_span() and \DDTrace\start_trace_span() with trace identifiers
--ENV--
DD_TRACE_DEBUG_PRNG_SEED=42
--FILE--
<?php

function prettyPrint($serializedClosedSpans) {
    foreach ($serializedClosedSpans as $idx => $span) {
        $str = str_repeat("\t", $idx);
        $parent = isset($span['parent_id']) ? $span['parent_id'] : "None";
        $str .= "Span {$span['span_id']} - Trace {$span['trace_id']} - Parent {$parent}";
        echo $str . PHP_EOL;
    }
}

ini_set("datadog.trace.generate_root_span", false);

\DDTrace\start_span(0, 42, 84);
\DDTrace\start_span();
\DDTrace\close_span();
\DDTrace\close_span();

prettyPrint(dd_trace_serialize_closed_spans());

\DDTrace\start_trace_span(0, 84, 42);
\DDTrace\start_trace_span();
\DDTrace\close_span();
\DDTrace\close_span();

prettyPrint(dd_trace_serialize_closed_spans());

ini_set("datadog.trace.generate_root_span", true);

\DDTrace\start_span(0, 42, 84);
\DDTrace\start_span();
\DDTrace\close_span();
\DDTrace\close_span();

prettyPrint(dd_trace_serialize_closed_spans());

\DDTrace\start_trace_span(0, 42, 84);
\DDTrace\start_trace_span();
\DDTrace\close_span();
\DDTrace\close_span();

prettyPrint(dd_trace_serialize_closed_spans());

ini_set("datadog.trace.128_bit_traceid_logging_enabled", "1");

\DDTrace\start_trace_span(0, 42, 184467440737095516);
\DDTrace\start_trace_span();
\DDTrace\close_span();
\DDTrace\close_span();

?>
--EXPECT--
Span 42 - Trace 84 - Parent None
	Span 11788048577503494824 - Trace 84 - Parent 42
Span 84 - Trace 42 - Parent None
	Span 2513787319205155662 - Trace 2513787319205155662 - Parent None
Span 42 - Trace 10598951352238613536 - Parent 10598951352238613536
	Span 6878563960102566144 - Trace 10598951352238613536 - Parent 42
Span 42 - Trace 84 - Parent None
	Span 7199227068870524257 - Trace 7199227068870524257 - Parent None
