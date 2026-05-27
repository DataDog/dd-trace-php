--TEST--
Security-testing headers are forwarded to the inferred proxy span
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED=1
HTTP_X_DD_PROXY=aws-apigateway
HTTP_X_DD_PROXY_REQUEST_TIME_MS=100
HTTP_X_DD_PROXY_PATH=/test
HTTP_X_DD_PROXY_HTTPMETHOD=GET
HTTP_X_DD_PROXY_DOMAIN_NAME=example.com
HTTP_X_DD_PROXY_STAGE=aws-prod
HTTP_X_DATADOG_ENDPOINT_SCAN=endpoint-scan-uuid
HTTP_X_DATADOG_SECURITY_TEST=security-test-uuid
METHOD=GET
SERVER_NAME=localhost:8888
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo
DD_TRACE_DEBUG_PRNG_SEED=42
--GET--
foo=bar
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();

// The PHP service-entry span has a parent_id pointing to the inferred span;
// the inferred span itself has no parent_id (it is the trace root).
$rootSpan = null;
$inferredSpan = null;
foreach ($spans as $span) {
    if (!isset($span['parent_id'])) {
        $inferredSpan = $span;
    } else {
        $rootSpan = $span;
    }
}

// Tags must be present on the PHP service-entry span
var_dump($rootSpan['meta']['http.request.headers.x-datadog-endpoint-scan'] ?? 'NOT SET');
var_dump($rootSpan['meta']['http.request.headers.x-datadog-security-test'] ?? 'NOT SET');
// And forwarded to the inferred proxy span
var_dump($inferredSpan['meta']['http.request.headers.x-datadog-endpoint-scan'] ?? 'NOT SET');
var_dump($inferredSpan['meta']['http.request.headers.x-datadog-security-test'] ?? 'NOT SET');
?>
--EXPECT--
string(18) "endpoint-scan-uuid"
string(18) "security-test-uuid"
string(18) "endpoint-scan-uuid"
string(18) "security-test-uuid"
