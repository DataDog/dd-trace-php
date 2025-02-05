--TEST--
Should create parent and child spans for a 200
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_AUTOFINISH_SPANS=1
DD_SERVICE=aws-server
DD_ENV=dev
DD_VERSION=1.0

DD_TRACE_DEBUG=1

DD_TRACE_INFERRED_PROXY_SERVICES_ENABLED=true
HTTP_X_DD_PROXY=aws-apigateway
HTTP_X_DD_PROXY_REQUEST_TIME_MS=100
HTTP_X_DD_PROXY_PATH=/test
HTTP_X_DD_PROXY_HTTPMETHOD=GET
HTTP_X_DD_PROXY_DOMAIN_NAME=example.com
HTTP_X_DD_PROXY_STAGE=dev

METHOD=GET
SERVER_NAME=localhost:8888
REQUEST_URI=/foo
--GET--
foo=bar
--FILE--
<?php

\DDTrace\start_span(120);
$span = \DDTrace\start_span(130);
$span->name = "child";

\DDTrace\root_span()->meta['foo'] = 'bar';

\DDTrace\close_span(130);
\DDTrace\close_span(140);

$span = dd_trace_serialize_closed_spans();

echo json_encode($span, JSON_PRETTY_PRINT);
?>
--EXPECTF--
