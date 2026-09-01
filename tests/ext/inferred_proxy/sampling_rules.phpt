--TEST--
Priority sampling rules should use the inferred span's service & resource
--ENV--
DD_TRACE_SAMPLING_RULES=[{"sample_rate": 0.7, "service": "foo", "resource": "bar"},{"sample_rate": 0.3, "service": "example.com", "resource": "GET \/test"}]
DD_TRACE_SAMPLING_RULES_FORMAT=regex

DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_SERVICE=foo

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

DD_TRACE_DEBUG_PRNG_SEED=42
--INI--
pcre.jit=0
--GET--
foo=bar
--FILE--
<?php
include __DIR__ . '/../sandbox/dd_dumper.inc';

$parent = \DDTrace\start_span(0.120);
\DDTrace\close_span();

echo json_encode(dd_clean_spans(), JSON_PRETTY_PRINT);
?>
--EXPECTF--
[
    {
        "trace_id": "13930160852258120406",
        "trace_id_high": "%s",
        "span_id": "13930160852258120406",
        "parent_id": "11788048577503494824",
        "start": 120000000,
        "duration": %d,
        "name": "web.request",
        "resource": "GET \/foo",
        "service": "foo",
        "type": "web",
        "span_kind": 2,
        "sampling_priority": 2,
        "sampling_mechanism": 3,
        "attributes": {
            "runtime-id": "%s",
            "http.url": "http:\/\/localhost:8888\/foo",
            "http.method": "GET",
            "_dd.p.ksr": "0.3",
            "http.status_code": "200",
            "process_id": %d,
            "php.compilation.total_time_ms": %f,
            "php.memory.peak_usage_bytes": %d,
            "php.memory.peak_real_usage_bytes": %d
        }
    },
    {
        "trace_id": "13930160852258120406",
        "trace_id_high": "%s",
        "span_id": "11788048577503494824",
        "start": 100000000,
        "duration": %d,
        "name": "aws.apigateway",
        "resource": "GET \/test",
        "service": "example.com",
        "type": "web",
        "component": "aws-apigateway",
        "span_kind": 1,
        "sampling_priority": 2,
        "sampling_mechanism": 3,
        "attributes": {
            "http.method": "GET",
            "http.url": "example.com\/test",
            "stage": "aws-prod",
            "http.status_code": "200",
            "_dd.inferred_span": 1,
            "_dd.rule_psr": 0.3,
            "_dd.p.ksr": "0.3"
        }
    }
]
