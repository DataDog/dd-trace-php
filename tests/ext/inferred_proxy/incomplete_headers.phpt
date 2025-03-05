--TEST--
An Inferred Span should not be created on missing headers
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_AUTOFINISH_SPANS=1
DD_SERVICE=aws-server
DD_ENV=local-prod
DD_VERSION=1.0

DD_TRACE_DEBUG=0

DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED=1
HTTP_X_DD_PROXY=aws-apigateway

METHOD=GET
SERVER_NAME=localhost:8888
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo

DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_AGENT_FLUSH_AFTER_N_REQUESTS=1
DD_TRACE_AGENT_FLUSH_INTERVAL=666
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0

DD_TRACE_DEBUG_PRNG_SEED=42
--GET--
foo=bar
--FILE--
<?php

include __DIR__ . '/../includes/request_replayer.inc';

$rr = new RequestReplayer;

$parent = \DDTrace\start_span(0.120);
$span = \DDTrace\start_span(0.130);
$span->name = "child";

dd_trace_close_all_spans_and_flush(); // Simulates end of request

$body = json_decode($rr->waitForDataAndReplay()["body"], true);
echo json_encode($body, JSON_PRETTY_PRINT);
?>
--EXPECTF--
[
    [
        {
            "trace_id": "13930160852258120406",
            "span_id": "13930160852258120406",
            "start": 120000000,
            "duration": %d,
            "name": "web.request",
            "resource": "GET \/foo",
            "service": "aws-server",
            "type": "web",
            "meta": {
                "runtime-id": "%s",
                "http.url": "http:\/\/localhost:8888\/foo",
                "http.method": "GET",
                "_dd.p.dm": "-0",
                "env": "local-prod",
                "version": "1.0",
                "http.status_code": "200",
                "_dd.p.tid": "%s"
            },
            "metrics": {
                "process_id": %d,
                "_dd.agent_psr": 1,
                "_sampling_priority_v1": 1,
                "php.compilation.total_time_ms": %f,
                "php.memory.peak_usage_bytes": %f,
                "php.memory.peak_real_usage_bytes": %f
            }
        },
        {
            "trace_id": "13930160852258120406",
            "span_id": "11788048577503494824",
            "parent_id": "13930160852258120406",
            "start": 130000000,
            "duration": %d,
            "name": "child",
            "resource": "child",
            "service": "aws-server",
            "type": "web",
            "meta": {
                "env": "local-prod",
                "version": "1.0"
            }
        }
    ]
]