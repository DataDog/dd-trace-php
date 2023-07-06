--TEST--
Decoding a post request with a JSON content type
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_POST_DATA_PARAM_ALLOWED=*
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
METHOD=POST
DD_TRACE_DEBUG=1
--POST_RAW--
Content-Type: text/json
{"name":"default output handler","type":0,"flags":112,"level":0,"chunk_size":0,"buffer_size":16384,"buffer_used":3}
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
var_dump(dd_trace_serialize_closed_spans());
--EXPECT--