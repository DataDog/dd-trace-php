--TEST--
Client-side stats: a rejected chunk goes to stats only, unless one of its spans has an error
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
<?php
if (PHP_VERSION_ID < 70400) die("skip: Before PHP 7.4, the skip-task would cause the sidecar to fetch the info already.");
if (PHP_VERSION_ID >= 80100) {
    echo "nocache\n";
}
$ctx = stream_context_create([
    'http' => [
        'method' => 'PUT',
        'header' => [
            'Content-Type: application/json',
            'X-Datadog-Test-Session-Token: css_p0_error_chunk',
        ],
        'content' => json_encode(['version' => '7.65.0', 'client_drop_p0s' => true]),
    ]
]);
file_get_contents('http://request-replayer/set-agent-info', false, $ctx);
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_TRACE_STATS_COMPUTATION_ENABLED=1
DD_TRACE_SAMPLE_RATE=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--INI--
datadog.trace.agent_test_session_token=css_p0_error_chunk
--FILE--
<?php

dd_trace_internal_fn('await_agent_info');

function trace($name, $exception = null) {
    \DDTrace\start_span()->name = $name;
    $child = \DDTrace\start_span();
    $child->name = "$name.child";
    $child->exception = $exception;
    \DDTrace\close_span();
    \DDTrace\close_span();
    $payload = dd_trace_serialize_closed_spans();
    $chunks = isset($payload['chunks']) ? $payload['chunks'] : [];
    echo "$name: ", count($chunks), " chunk(s)\n";
    foreach ($chunks as $chunk) {
        echo "  priority <= 0: ", var_export($chunk['priority'] <= 0, true), "\n";
        foreach ($chunk['spans'] as $span) {
            echo "  ", $span['name'], "\n";
        }
    }
}

trace("ok");
trace("failing", new Exception("boom"));

?>
--EXPECT--
ok: 0 chunk(s)
failing: 1 chunk(s)
  priority <= 0: true
  failing
  failing.child
