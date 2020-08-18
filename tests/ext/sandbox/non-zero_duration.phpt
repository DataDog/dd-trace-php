--TEST--
Test that the sandbox API has non-zero durations
--FILE--
<?php

DDTrace\trace_function(
    'function1',
    function (DDTrace\SpanData $span) {
        echo "function1 traced.\n";
        $span->service = 'phpt';
    }
);

function function1() {
    echo __FUNCTION__, " called.\n";
}

function1();

$closedSpans = dd_trace_serialize_closed_spans();
if (empty($closedSpans)) {
    die("No spans found; test is not set up as expected.\n");
}
if (count($closedSpans) !== 1) {
    die("More than one span found; test is not set up as expected\n.");
}

if (!isset($closedSpans[0]['service'])) {
    die("Service not found!\n");
}
if ($closedSpans[0]['service'] !== 'phpt') {
   die("Unexpected service '{$closedSpans[0]['service']}'; expected 'phpt'.\n");
}

function duration_missing_or_zero(array $span) {
    return !isset($span['duration']) || $span['duration'] == 0;
}

$result = array_filter($closedSpans, 'duration_missing_or_zero');

if (empty($result)) {
    echo "All spans had durations.\n";
} else {
    echo "Spans were missing durations!\n";
    var_dump($result);
}

// let's make sure our filter function is working as expected
$closedSpans[0]['duration'] = 0;
$result = array_filter($closedSpans, 'duration_missing_or_zero');
$errors = count($result) == 1 ? 0 : 1;

unset($closedSpans[0]['duration']);
$result = array_filter($closedSpans, 'duration_missing_or_zero');
$errors += count($result) == 1 ? 0 : 1;

if ($errors) {
    die("The test itself seems to have a problem!\n");
}

?>
--EXPECT--
function1 called.
function1 traced.
All spans had durations.

