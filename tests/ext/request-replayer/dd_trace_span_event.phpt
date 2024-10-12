--TEST--
DDTrace\SpanEvent serialization with attributes
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
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
$spans = $root["chunks"][0]["spans"] ?? $root[0];
$span = $spans[0];
var_dump($span['meta']['events']);
?>
--EXPECT--
In testMethod
string(134) "[{"name":"event-name","time_unix_nano":1720037568765201300,"attributes":{"arg1":"value1","int_array":[3,4],"string_array":["5","6"]}}]"
