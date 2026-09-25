--TEST--
DDTrace\ExceptionSpanEvent serialization with overridden attributes
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=0
--INI--
datadog.trace.agent_test_session_token=dd_trace_exception_span_event
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

use DDTrace\SpanData;
use DDTrace\ExceptionSpanEvent;

class ExceptionClass {
    public function exceptionMethod() {
        throw new \Exception("Exception in method");
    }
}

DDTrace\trace_method('ExceptionClass', 'exceptionMethod', function (SpanData $span) {
    $span->name = 'ExceptionClass.exceptionMethod';
    $exception = new \Exception("initial exception");
    $spanEvent = new ExceptionSpanEvent($exception, [
        "exception.message" => "override message",
        "custom.attribute" => "custom value"
    ]);
    $span->events[] = $spanEvent;
});

$rr = new RequestReplayer();
$rr->replayRequest(); // cleanup possible leftover

try {
    $exceptionClass = new ExceptionClass();
    $exceptionClass->exceptionMethod();
} catch (\Exception $e) {
    echo 'Caught exception: ' . $e->getMessage() . PHP_EOL;
}

$replay = $rr->waitForDataAndReplay();
$root = json_decode($replay["body"], true);
$spans = $root["chunks"][0]["spans"] ?? $root[0];
$span = $spans[0];

// This test pins the in-process sender (v0.4-only): events are legacy `events` meta JSON, no
// `span_events` field. Handle both shapes anyway; ddAnyValueToPhp() unwraps the v1 AnyValue form.
function ddAnyValueToPhp($v) {
    if (!is_array($v) || !array_key_exists('type', $v)) {
        return $v; // already a plain value (legacy `events` meta JSON)
    }
    switch ($v['type']) {
        case 0: return $v['string_value'];
        case 1: return $v['bool_value'];
        case 2: return $v['int_value'];
        case 3: return $v['double_value'];
        case 4: return array_map('ddAnyValueToPhp', $v['array_value']['values']);
        default: return $v;
    }
}
if (isset($span['span_events'])) {
    $event = $span['span_events'][0];
} else {
    $event = json_decode($span['meta']['events'], true)[0];
}
$attrs = $event['attributes'];
var_dump($event['name']);
// The user-provided "exception.message" overrides the exception's own message (builder last-write-wins).
var_dump(ddAnyValueToPhp($attrs['exception.message']));
var_dump(ddAnyValueToPhp($attrs['exception.type']));
var_dump(ddAnyValueToPhp($attrs['custom.attribute']));
var_dump(ddAnyValueToPhp($attrs['exception.stacktrace']));
?>
--EXPECTF--
Caught exception: Exception in method
string(9) "exception"
string(16) "override message"
string(9) "Exception"
string(12) "custom value"
string(%d) "#0 %s(%d): ExceptionClass->{%s}()
#1 %s(%d): ExceptionClass->exceptionMethod()
#2 {main}"
