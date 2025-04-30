--TEST--
An Inferred Span should not be created on missing headers
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
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

DD_TRACE_DEBUG_PRNG_SEED=42
--GET--
foo=bar
--FILE--
<?php

$parent = \DDTrace\start_span(0.120);
$span = \DDTrace\start_span(0.130);
$span->name = "child";

\DDTrace\close_span();
\DDTrace\close_span();

echo json_encode(dd_trace_serialize_closed_spans(), JSON_PRETTY_PRINT);
?>
--EXPECTF--
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
            "_dd.p.dm": "-0",
            "_dd.p.tid": "%s",
            "env": "local-prod",
            "http.method": "GET",
            "http.status_code": "200",
            "http.url": "http:\/\/localhost:8888\/foo",
            "runtime-id": "%s",
            "version": "1.0"
        },
        "metrics": {
            "_dd.agent_psr": 1,
            "_sampling_priority_v1": 1,
            "php.compilation.total_time_ms": %f,
            "php.memory.peak_real_usage_bytes": %d,
            "php.memory.peak_usage_bytes": %d,
            "process_id": %d
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