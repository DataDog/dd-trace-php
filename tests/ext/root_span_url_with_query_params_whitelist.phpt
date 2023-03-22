--TEST--
Root span with query params whitelist
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_URL_QUERY_PARAM_ALLOWED=password
HTTPS=off
HTTP_HOST=localhost:9999
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo?password=value&some=query&param&eters
QUERY_STRING=password=value&some=query&param&eters
METHOD=GET
--GET--
password=value&some=query&param&eters
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']["http.url"]);
?>
--EXPECT--
string(41) "https://localhost:9999/foo?password=value"
