--TEST--
Single-span-sampled p0 root with an inferred span and agent stats does not crash (F1 NULL-guard)
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
            'X-Datadog-Test-Session-Token: css_inferred_span_sampling',
        ],
        'content' => json_encode(['version' => '7.65.0', 'client_drop_p0s' => true]),
    ]
]);
file_get_contents('http://request-replayer/set-agent-info', false, $ctx);
?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_INTERVAL=333
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_TRACE_SIDECAR_TRACE_SENDER=1
DD_TRACE_STATS_COMPUTATION_ENABLED=1
DD_SERVICE=aws-server
DD_TRACE_SAMPLE_RATE=0
DD_SPAN_SAMPLING_RULES=[{"service":"aws-server","name":"web.request","sample_rate":1.0}]
DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED=1
HTTP_X_DD_PROXY=aws-apigateway
HTTP_X_DD_PROXY_REQUEST_TIME_MS=1742285908783
HTTP_X_DD_PROXY_PATH=/test
HTTP_X_DD_PROXY_HTTPMETHOD=GET
HTTP_X_DD_PROXY_DOMAIN_NAME=example.com
HTTP_X_DD_PROXY_STAGE=aws-prod
METHOD=GET
SERVER_NAME=localhost:8888
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo
DD_TRACE_DEBUG_PRNG_SEED=42
--INI--
datadog.trace.agent_test_session_token=css_inferred_span_sampling
--FILE--
<?php

// Block until the sidecar has received the agent's /info response before stats are computed
dd_trace_internal_fn('await_agent_info');

// First span becomes the entrypoint root (web.request); the proxy headers attach an inferred
// (aws.apigateway) parent span to it.
$root = \DDTrace\start_span();
$root->name = "web.request";
\DDTrace\close_span();

echo "before flush\n";
// Serialization runs here: the p0 root is single-span-sampled (so it builds), then its inferred
// parent recurses and is dropped into the stats concentrator, returning the {0} sentinel sink.
// Without the NULL guard the parent's transfer/set_error deref a NULL builder and crash.
dd_trace_internal_fn('synchronous_flush', 5000);
echo "after flush\n";

?>
--EXPECT--
before flush
after flush
