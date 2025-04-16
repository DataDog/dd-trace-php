--TEST--
Headers values are mapped to expected tag key
--ENV--
HTTP_CONTENT_TYPE=text/plain
HTTP_CUSTOM_HEADER=custom-header-value
HTTP_HEADER1=val
HTTP_HEADER2=v a l
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HEADER_TAGS=Content-Type,Custom-Header:custom-HeaderKey,header1: t a g ,header2:tag
--GET--
application_key=123
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']['http.request.headers.content-type']);
var_dump($spans[0]['meta']['custom-HeaderKey']);
var_dump($spans[0]['meta']['t a g']);
var_dump($spans[0]['meta']['tag']);
?>
--EXPECT--
string(10) "text/plain"
string(19) "custom-header-value"
string(3) "val"
string(5) "v a l"
