--TEST--
root span with DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_URL_AS_RESOURCE_NAMES_ENABLED=1
DD_TRACE_RESOURCE_URI_QUERY_PARAM_ALLOWED=param
DD_TRACE_HTTP_URL_QUERY_PARAM_ALLOWED=*
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo?some=query&param&eters
QUERY_STRING=some=query&param&eters
METHOD=GET
--GET--
some=query&param&eters
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['resource']);
var_dump($spans[0]['meta']["http.url"]);
?>
--EXPECT--
string(14) "GET /foo?param"
string(49) "https://localhost:9999/foo?some=query&param&eters"
