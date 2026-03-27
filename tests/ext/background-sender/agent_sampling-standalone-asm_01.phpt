--TEST--
Sample rate is changed to 0 after first call during a minute when STANDALONE ASM is enabled and no asm events
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_APM_TRACING_ENABLED=0
--FILE--
<?php
// Each start_span() with no existing root span creates a DDTrace\RootSpanData.
// The standalone ASM limiter (called during close_span()) uses 60-second time buckets:
//   - span 1: last_hit=0 at process start, current bucket > 0 → tick() true  → normal sampling (priority=1)
//   - span 2: same 60-second bucket                           → tick() false → AUTO_REJECT (priority=0)
//   - span 3: same 60-second bucket                           → tick() false → AUTO_REJECT (priority=0)
//
// samplingPriority is set synchronously on the RootSpanData C struct during close_span();
// no flush, no network I/O, and no Valgrind-hostile curl operations needed.
$span1 = \DDTrace\start_span();
\DDTrace\close_span();

$span2 = \DDTrace\start_span();
\DDTrace\close_span();

$span3 = \DDTrace\start_span();
\DDTrace\close_span();

if ($span1->samplingPriority === 1 && $span2->samplingPriority === 0 && $span3->samplingPriority === 0) {
    echo "All good\n";
} else {
    echo "Got: {$span1->samplingPriority}, {$span2->samplingPriority}, {$span3->samplingPriority}\n";
}
echo "Done\n";
?>
--EXPECT--
All good
Done
