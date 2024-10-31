--TEST--
Headers values are mapped to expected tag key
--ENV--
HTTP_CONTENT_TYPE=text/plain
HTTP_CUSTOM_HEADER=custom-header-value
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HEADER_TAGS=Content-Type,Custom-Header:custom-header-key
--GET--
application_key=123
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']['http.request.headers.content-type']);
var_dump($spans[0]['meta']['custom-header-key']);
?>
--EXPECT--
string(10) "text/plain"
string(13) "custom-header-value"
