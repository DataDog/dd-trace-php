--TEST--
DDTrace\ExceptionSpanEvent serialization with overridden attributes
--SKIPIF--
<?php
if (strncasecmp(PHP_OS, "WIN", 3) == 0) die('skip: the in-process sender is not available on Windows');
include __DIR__ . '/../includes/skipif_no_dev_env.inc';
?>
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
$span = $root[0][0];

// The in-process sender (v0.4-only) writes span events as legacy `events` meta JSON.
$event = json_decode($span['meta']['events'], true)[0];
$attrs = $event['attributes'];
var_dump($event['name']);
// The user-provided "exception.message" overrides the exception's own message (builder last-write-wins).
var_dump($attrs['exception.message']);
var_dump($attrs['exception.type']);
var_dump($attrs['custom.attribute']);
var_dump($attrs['exception.stacktrace']);
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
