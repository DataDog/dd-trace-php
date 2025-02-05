--TEST--
Should create parent and child spans for error
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

function index()
{
    http_response_code(500);
    throw new \Exception('An exception message');
}

\DDTrace\trace_function('index', function (\DDTrace\SpanData $span) {
    $span->name = 'index';
});

try {
    index();
} catch (\Exception $e) {
    $span = dd_trace_serialize_closed_spans();
    echo json_encode($span, JSON_PRETTY_PRINT);
}

?>
--EXPECTF--
