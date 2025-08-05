--TEST--
API Gateway span should be created from consuming distributed tracing headers
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_SERVICE=aws-server
DD_ENV=local-prod
DD_VERSION=1.0

DD_TRACE_DEBUG=0

DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED=1

METHOD=GET
SERVER_NAME=localhost:8888
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo

DD_TRACE_DEBUG_PRNG_SEED=42
--FILE--
<?php

$parent = \DDTrace\start_span(0.120);
$headers = [
    'x-dd-proxy' => 'aws-apigateway',
    'x-dd-proxy-request-time-ms' => '1739261376000',
    'x-dd-proxy-path' => '/test',
    'x-dd-proxy-httpmethod' => 'GET',
    'x-dd-proxy-domain-name' => 'example.com',
    'x-dd-proxy-stage' => 'aws-prod',
];
\DDTrace\consume_distributed_tracing_headers(function ($key) use ($headers) {
    return $headers[$key] ?? null;
});

$span = \DDTrace\start_span(0.130);
$span->name = "child";

\DDTrace\close_span();
\DDTrace\close_span(); // Should close the API Gateway span

echo json_encode(dd_trace_serialize_closed_spans(), JSON_PRETTY_PRINT);

?>
--EXPECTF--
[
    {
        "trace_id": "13930160852258120406",
        "span_id": "13930160852258120406",
        "parent_id": "11788048577503494824",
        "start": 120000000,
        "duration": %d,
        "name": "consume_distributed_tracing_headers.php",
        "resource": "consume_distributed_tracing_headers.php",
        "service": "aws-server",
        "type": "cli",
        "meta": {
            "env": "local-prod",
            "http.url": "http:\/\/localhost:8888\/foo",
            "runtime-id": "%s",
            "version": "1.0"
        },
        "metrics": {
            "php.compilation.total_time_ms": %f,
            "php.memory.peak_real_usage_bytes": %d,
            "php.memory.peak_usage_bytes": %d,
            "process_id": %d
        }
    },
    {
        "trace_id": "13930160852258120406",
        "span_id": "11788048577503494824",
        "start": %d,
        "duration": %d,
        "name": "aws.apigateway",
        "resource": "GET \/test",
        "service": "example.com",
        "type": "web",
        "meta": {
            "_dd.p.dm": "-0",
            "_dd.p.tid": "%s",
            "component": "aws-apigateway",
            "env": "local-prod",
            "http.method": "GET",
            "http.url": "example.com\/test",
            "stage": "aws-prod",
            "version": "1.0"
        },
        "metrics": {
            "_dd.agent_psr": 1,
            "_dd.inferred_span": 1,
            "_sampling_priority_v1": 1
        }
    },
    {
        "trace_id": "13930160852258120406",
        "span_id": "13874630024467741450",
        "parent_id": "13930160852258120406",
        "start": 130000000,
        "duration": %d,
        "name": "child",
        "resource": "child",
        "service": "aws-server",
        "type": "cli",
        "meta": {
            "env": "local-prod",
            "version": "1.0"
        }
    }
]