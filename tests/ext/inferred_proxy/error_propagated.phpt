--TEST--
Should create parent and child spans for error
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
HTTP_X_DD_PROXY_REQUEST_TIME_MS=100
HTTP_X_DD_PROXY_PATH=/test
HTTP_X_DD_PROXY_HTTPMETHOD=GET
HTTP_X_DD_PROXY_DOMAIN_NAME=example.com
HTTP_X_DD_PROXY_STAGE=aws-prod

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

function oops()
{
    http_response_code(500);
    throw new \Exception('An exception occurred');
}

\DDTrace\trace_function('oops', function($span) {
    $span->name = 'request';
});

try {
    oops();
} catch (\Exception $e) {
    //
}

dd_trace_close_all_spans_and_flush(); // Simulates end of request

$body = json_decode($rr->waitForDataAndReplay()["body"], true);
echo json_encode($body, JSON_PRETTY_PRINT);
?>
--EXPECTF--
[
    [
        {
            "trace_id": "13930160852258120406",
            "span_id": "11788048577503494824",
            "start": 100000000,
            "duration": %d,
            "name": "aws.apigateway",
            "resource": "GET \/test",
            "service": "example.com",
            "type": "web",
            "error": 1,
            "meta": {
                "http.method": "GET",
                "http.url": "example.com\/test",
                "stage": "aws-prod",
                "_dd.inferred_span": "1",
                "component": "aws-apigateway",
                "_dd.p.dm": "-0",
                "error.message": "Uncaught Exception (500): An exception occurred in %s\/build_extension\/tests\/ext\/inferred_proxy\/error_propagated.php:10",
                "error.type": "Exception",
                "error.stack": "#0 %s\/tmp\/build_extension\/tests\/ext\/inferred_proxy\/error_propagated.php(18): oops()\n#1 {main}",
                "env": "local-prod",
                "version": "1.0",
                "http.status_code": "500",
                "_dd.p.tid": "%s"
            },
            "metrics": {
                "_dd.agent_psr": 1,
                "_sampling_priority_v1": 1
            }
        },
        {
            "trace_id": "13930160852258120406",
            "span_id": "13930160852258120406",
            "parent_id": "11788048577503494824",
            "start": %d,
            "duration": %d,
            "name": "request",
            "resource": "GET \/foo",
            "service": "aws-server",
            "type": "web",
            "error": 1,
            "meta": {
                "runtime-id": "%s",
                "http.url": "http:\/\/localhost:8888\/foo",
                "http.method": "GET",
                "env": "local-prod",
                "version": "1.0",
                "error.message": "Uncaught Exception (500): An exception occurred in %s\/tmp\/build_extension\/tests\/ext\/inferred_proxy\/error_propagated.php:10",
                "error.type": "Exception",
                "error.stack": "#0 %s\/tmp\/build_extension\/tests\/ext\/inferred_proxy\/error_propagated.php(18): oops()\n#1 {main}",
                "http.status_code": "500"
            },
            "metrics": {
                "process_id": %d,
                "php.compilation.total_time_ms": %f,
                "php.memory.peak_usage_bytes": %d,
                "php.memory.peak_real_usage_bytes": %d
            }
        }
    ]
]