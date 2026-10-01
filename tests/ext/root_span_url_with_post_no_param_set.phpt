--TEST--
Empty post request without whitelisting
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
METHOD=POST
--POST--
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
$postKeys = array_filter(array_keys($spans[0]['attributes']), function ($key) {
    return strpos($key, 'http.request.post') === 0;
});
var_dump($postKeys);
?>
--EXPECT--
array(0) {
}
