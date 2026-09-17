--TEST--
Should create parent and child spans for a 200
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_SERVICE=aws-server
DD_ENV=local-prod
DD_VERSION=1.0

DD_TRACE_DEBUG=0

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
--GET--
foo=bar
--FILE--
<?php
include __DIR__ . '/../sandbox/dd_dumper.inc';

$requestTimeMs = 1742285908783;

$parent = \DDTrace\start_span();
$span = \DDTrace\start_span();
$span->name = "child";

\DDTrace\root_span()->meta['foo'] = 'bar'; // It MUST set it on $parent

\DDTrace\close_span();
\DDTrace\close_span();

$endTimeMs = (int)(microtime(true) * 1000);

$serializedSpans = dd_clean_spans();
echo json_encode($serializedSpans, JSON_PRETTY_PRINT);

$actualDurationNs = $serializedSpans[1]["duration"];
$expectedDurationNs = ($endTimeMs - $requestTimeMs) * 1000 * 1000;
$percentageDifference = abs($actualDurationNs - $expectedDurationNs) / $expectedDurationNs * 100;
if ($percentageDifference > 0.01) { // 0.01% difference for the sake of the test
    echo "Expected duration: $expectedDurationNs\n";
    echo "Percentage difference: $percentageDifference%\n";
} else {
    echo "Duration is within 0.01% of expected duration\n";
}
?>
--EXPECTF--
[
    {
        "trace_id": "13930160852258120406",
        "trace_id_high": "%s",
        "span_id": "13930160852258120406",
        "parent_id": "11788048577503494824",
        "start": %d,
        "duration": %d,
        "name": "web.request",
        "resource": "GET \/foo",
        "service": "aws-server",
        "type": "web",
        "env": "local-prod",
        "version": "1.0",
        "span_kind": 2,
        "sampling_priority": 1,
        "sampling_mechanism": 0,
        "attributes": {
            "runtime-id": "%s",
            "http.url": "http:\/\/localhost:8888\/foo",
            "http.method": "GET",
            "foo": "bar",
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
        "start": 1742285908783000000,
        "duration": %d,
        "name": "aws.apigateway",
        "resource": "GET \/test",
        "service": "example.com",
        "type": "web",
        "env": "local-prod",
        "version": "1.0",
        "component": "aws-apigateway",
        "span_kind": 1,
        "sampling_priority": 1,
        "sampling_mechanism": 0,
        "attributes": {
            "http.method": "GET",
            "http.url": "example.com\/test",
            "stage": "aws-prod",
            "http.status_code": "200",
            "_dd.inferred_span": 1,
            "_dd.agent_psr": 1
        }
    },
    {
        "trace_id": "13930160852258120406",
        "trace_id_high": "%s",
        "span_id": "13874630024467741450",
        "parent_id": "13930160852258120406",
        "start": %d,
        "duration": %d,
        "name": "child",
        "resource": "child",
        "service": "aws-server",
        "type": "web",
        "env": "local-prod",
        "version": "1.0",
        "span_kind": 1,
        "sampling_priority": 1,
        "sampling_mechanism": 0
    }
]Duration is within 0.01% of expected duration