--TEST--
DDTrace\ExceptionSpanEvent serialization with overridden attributes
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_AUTO_FLUSH_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
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

var_dump($span['meta']['events']);
?>
--EXPECTF--
Caught exception: Exception in method
string(509) "[{"name":"exception","time_unix_nano":%d,"attributes":{"exception.message":"override message","exception.type":"Exception","exception.stacktrace":"#0 \/home\/circleci\/app\/tmp\/build_extension\/tests\/ext\/request-replayer\/dd_trace_exception_span_event.php(10): ExceptionClass->{closure}()\n#1 \/home\/circleci\/app\/tmp\/build_extension\/tests\/ext\/request-replayer\/dd_trace_exception_span_event.php(28): ExceptionClass->exceptionMethod()\n#2 {main}","custom.attribute":"custom value"}}]"
