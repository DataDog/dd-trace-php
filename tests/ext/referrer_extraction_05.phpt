--TEST--
IPv6 address in referrer header
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
SERVER_NAME=localhost:8888
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo
METHOD=GET
HTTP_REFERER=https://[2001:db8::1]:8080/path?query=123#fragment
--GET--
foo=bar
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['attributes']['http.referrer_hostname'] ?? 'NOT SET');
?>
--EXPECT--
string(13) "[2001:db8::1]"