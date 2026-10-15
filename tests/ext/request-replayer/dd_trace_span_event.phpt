--TEST--
DDTrace\SpanEvent serialization with attributes
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
datadog.trace.agent_test_session_token=dd_trace_span_event
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

use DDTrace\SpanData;
use DDTrace\SpanEvent;

class TestClass {
    public function testMethod() {
        echo "In testMethod\n";
    }
}

DDTrace\trace_method('TestClass', 'testMethod', function (SpanData $span) {
    $span->name = 'TestClass.testMethod';
    $spanEvent = new SpanEvent("event-name", [
        'arg1' => 'value1',
        'int_array' => [3, 4],
        'string_array' => ["5", "6"]
    ], 1720037568765201300);
    $span->events[] = $spanEvent;
});

$rr = new RequestReplayer();
$test = new TestClass();
$test->testMethod();
$replay = $rr->waitForDataAndReplay();
$root = json_decode($replay["body"], true);
$span = $root[0][0];
// The in-process sender (v0.4-only) writes span events as legacy `events` meta JSON.
$event = json_decode($span['meta']['events'], true)[0];
$attrs = $event['attributes'];
var_dump($event['name'], $event['time_unix_nano']);
var_dump($attrs['arg1']);
var_dump($attrs['int_array']);
var_dump($attrs['string_array']);
?>
--EXPECT--
In testMethod
string(10) "event-name"
int(1720037568765201300)
string(6) "value1"
array(2) {
  [0]=>
  int(3)
  [1]=>
  int(4)
}
array(2) {
  [0]=>
  string(1) "5"
  [1]=>
  string(1) "6"
}
