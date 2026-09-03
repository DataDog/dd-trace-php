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
$spans = $root["chunks"][0]["spans"] ?? $root[0];
$span = $spans[0];
// The tracer always builds the native V1 payload, so span events are emitted as native span events
// (a `span_events` array) instead of the legacy meta["events"] JSON blob. This test pins the
// in-process sender (DD_TRACE_SIDECAR_TRACE_SENDER=0), which downgrades to the native V0.4
// `span_events` field: it is the only wire on which the request-replayer surfaces the native event
// shape (its v1 decoder folds events back into meta["events"] for v0.4 comparability). Native event
// attributes are OTEL AnyValue-typed maps; we assert them order-independently. Array/object
// attribute values have no native V1 attribute variant, so they are preserved as a JSON string.
$event = $span['span_events'][0];
$attrs = $event['attributes'];
var_dump($event['name'], $event['time_unix_nano']);
var_dump($attrs['arg1']['string_value']);
var_dump($attrs['int_array']['string_value']);
var_dump($attrs['string_array']['string_value']);
?>
--EXPECT--
In testMethod
string(10) "event-name"
int(1720037568765201300)
string(6) "value1"
string(5) "[3,4]"
string(9) "["5","6"]"
