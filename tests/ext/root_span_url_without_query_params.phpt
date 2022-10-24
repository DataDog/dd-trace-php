--TEST--
root span without query string in http.url
--SKIPIF--
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_URL_QUERY_PARAM_ALLOWED=''
HTTPS=off
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
var_dump($spans[0]['meta']["http.url"]);
?>
--EXPECT--
string(26) "https://localhost:9999/foo"
