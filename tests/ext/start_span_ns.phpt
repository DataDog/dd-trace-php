--TEST--
\DDTrace\start_span with nanoseconds
--FILE--
<?php

list($startTimeSec, $startTimeNsec) = \DDTrace\now();

$expected = (int)($startTimeSec * 1e9 + $startTimeNsec);

$span = \DDTrace\start_span($startTimeSec, $startTimeNsec);
$span->name = 'test_span';
\DDTrace\close_span();


$closedSpans = dd_trace_serialize_closed_spans();
$condition = $closedSpans[0]['start'] === $expected;
echo $condition ? 'PASS' : 'FAIL';

?>
--EXPECT--
PASS
