--TEST--
Client-side stats: a root is not top-level under an inferred span of the same service
--ENV--
DD_TRACE_STATS_COMPUTATION_ENABLED=true
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
DD_SERVICE=aws-server

DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED=1
HTTP_X_DD_PROXY=aws-apigateway
HTTP_X_DD_PROXY_REQUEST_TIME_MS=100
HTTP_X_DD_PROXY_PATH=/test
HTTP_X_DD_PROXY_HTTPMETHOD=GET
HTTP_X_DD_PROXY_STAGE=aws-prod

METHOD=GET
SERVER_NAME=localhost:8888
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo
--GET--
foo=bar
--FILE--
<?php
// Without a domain header, the inferred span falls back to the root's service.
$root = \DDTrace\start_span();
$child = \DDTrace\start_span();
$child->name = "child_other_service";
$child->service = "other-service";
\DDTrace\close_span();
\DDTrace\close_span();

foreach (dd_trace_serialize_closed_spans()['chunks'][0]['spans'] as $span) {
    echo $span["name"], " (", $span["service"], "): _dd.top_level=", isset($span["attributes"]["_dd.top_level"]) ? "1" : "not set", "\n";
}
?>
--EXPECT--
web.request (aws-server): _dd.top_level=not set
aws.apigateway (aws-server): _dd.top_level=not set
child_other_service (other-service): _dd.top_level=1
