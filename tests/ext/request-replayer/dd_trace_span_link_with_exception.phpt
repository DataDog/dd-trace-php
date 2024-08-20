--TEST--
Span Link serialization with non-null EG(exception) doesn't fail
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_AUTO_FLUSH_ENABLED=1
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
--INI--
datadog.trace.agent_test_session_token=dd_trace_span_link_with_exception
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

use DDTrace\SpanData;
use DDTrace\SpanLink;

class Foo
{
    public function bar()
    {
        $this->doException();
    }

    private function doException()
    {
        throw new Exception('Oops!');
    }
}

DDTrace\trace_method('Foo', 'bar', function (SpanData $span) {
    $span->name = 'Foo.bar';
    $spanLink = new SpanLink();
    $spanLink->traceId = "42";
    $spanLink->spanId = "6";
    $span->links[] = $spanLink;
});

$rr = new RequestReplayer();

$foo = new Foo();
try {
    $foo->bar();
} catch (Exception $e) {
    echo 'Caught exception: ' . $e->getMessage() . PHP_EOL;
}

$replay = $rr->waitForDataAndReplay();
$root = json_decode($replay["body"], true);
$spans = $root["chunks"][0]["spans"] ?? $root[0];
$span = $spans[0];
var_dump($span['meta']['error.message']);
var_dump($span['meta']['error.type']);
var_dump($span['meta']['error.stack']);
var_dump($span['meta']['_dd.span_links']);
?>
--EXPECTF--
Caught exception: Oops!
string(%d) "Uncaught Exception: Oops! in %sdd_trace_span_link_with_exception.php:17"
string(9) "Exception"
string(%d) "#0 %sdd_trace_span_link_with_exception.php(12): Foo->doException()
#1 %sdd_trace_span_link_with_exception.php(33): Foo->bar()
#2 {main}"
string(33) "[{"trace_id":"42","span_id":"6"}]"
