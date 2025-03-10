--TEST--
Span creation with distributed context
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
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

HTTP_X_DATADOG_TRACE_ID=1
HTTP_X_DATADOG_PARENT_ID=2
HTTP_X_DATADOG_ORIGIN=rum
HTTP_X_DATADOG_SAMPLING_PRIORITY=2

METHOD=GET
SERVER_NAME=localhost:8888
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo

DD_TRACE_DEBUG_PRNG_SEED=42
--GET--
foo=bar
--FILE--
<?php

$parent = \DDTrace\start_span(0.120);
$span = \DDTrace\start_span(0.130);
$span->name = "child";

\DDTrace\root_span()->meta['foo'] = 'bar'; // It MUST set it on $parent

\DDTrace\close_span();
\DDTrace\close_span();

echo json_encode(dd_trace_serialize_closed_spans(), JSON_PRETTY_PRINT);
?>
--EXPECTF--
[
    {
        "trace_id": "1",
        "span_id": "11788048577503494824",
        "parent_id": "2",
        "start": 100000000,
        "duration": %d,
        "name": "aws.apigateway",
        "resource": "GET \/test",
        "service": "example.com",
        "type": "web",
        "meta": {
            "_dd.p.dm": "-0",
            "http.method": "GET",
            "http.url": "example.com\/test",
            "stage": "aws-prod",
            "_dd.inferred_span": "1",
            "component": "aws-apigateway",
            "env": "local-prod",
            "version": "1.0",
            "http.status_code": "200",
            "_dd.origin": "rum",
            "_dd.p.tid": "0"
        },
        "metrics": {
            "_sampling_priority_v1": 2
        }
    },
    {
        "trace_id": "1",
        "span_id": "13930160852258120406",
        "parent_id": "11788048577503494824",
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
            "foo": "bar",
            "env": "local-prod",
            "version": "1.0",
            "http.status_code": "200",
            "_dd.origin": "rum"
        },
        "metrics": {
            "process_id": %d,
            "php.compilation.total_time_ms": %f,
            "php.memory.peak_usage_bytes": %d,
            "php.memory.peak_real_usage_bytes": %d
        }
    },
    {
        "trace_id": "1",
        "span_id": "13874630024467741450",
        "parent_id": "13930160852258120406",
        "start": 130000000,
        "duration": %d,
        "name": "child",
        "resource": "child",
        "service": "aws-server",
        "type": "web",
        "meta": {
            "env": "local-prod",
            "version": "1.0",
            "_dd.origin": "rum"
        }
    }
]