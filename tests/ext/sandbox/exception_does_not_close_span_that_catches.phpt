--TEST--
Exceptions do not close the span that catches it
--FILE--
<?php
use DDTrace\SpanData;

function main() {
    return handle();
}

function handle() {
    try {
        return doException();
    } catch (Exception $e) {
        return handleException($e);
    }
}

function handleException(Exception $e) {
    echo 'Exception was handled: ' . $e->getMessage() . PHP_EOL;
    return '-HANDLED';
}

function doException() {
    try {
        throw new Exception('Oops!');
    } catch (RuntimeException $e) {
        echo 'You should not see this';
    }
    return 'You should not see this either';
}

DDTrace\trace_function('main', function(SpanData $s, $a, $retval) {
    $s->name = 'main';
    $s->resource = $retval;
});

DDTrace\trace_function('handle', function(SpanData $s, $a, $retval) {
    $s->name = 'handle';
    $s->resource = $retval;
});

DDTrace\trace_function('handleException', function(SpanData $s, $a, $retval) {
    $s->name = 'handleException';
    $s->resource = $retval;
});

echo main() . PHP_EOL;

list($mainSpan, $handleSpan, $handleExceptionSpan) = dd_trace_serialize_closed_spans();

echo $mainSpan['name'] . $mainSpan['resource'] . PHP_EOL;
var_dump((PHP_VERSION_ID >= 70000) == isset($mainSpan['parent_id'])); // root span

echo $handleSpan['name'] . $handleSpan['resource'] . PHP_EOL;
var_dump($handleSpan['parent_id'] === $mainSpan['span_id']);

echo $handleExceptionSpan['name'] . $handleExceptionSpan['resource'] . PHP_EOL;
var_dump($handleExceptionSpan['parent_id'] === $handleSpan['span_id']);
?>
--EXPECT--
Exception was handled: Oops!
-HANDLED
main-HANDLED
bool(true)
handle-HANDLED
bool(true)
handleException-HANDLED
bool(true)
