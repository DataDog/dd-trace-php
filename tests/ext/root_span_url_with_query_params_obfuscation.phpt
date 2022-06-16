--TEST--
root span with http.url and obfuscated query string
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: Obfuscation only present in PHP 7+"); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_URL_QUERY_PARAM_ALLOWED=*
HTTPS=off
SERVER_NAME=localhost:8888
HTTP_HOST=localhost:9999
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo?key1=val1&token=a0b21ce2-006f-4cc6-95d5-d7b550698482&key2=val2&password=something
QUERY_STRING=key1=val1&token=a0b21ce2-006f-4cc6-95d5-d7b550698482&key2=val2&password=something
METHOD=GET
--GET--
key1=val1&token=a0b21ce2-006f-4cc6-95d5-d7b550698482&key2=val2&password=something
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']["http.url"]);
?>
--EXPECT--
string(68) "https://localhost:9999/foo?key1=val1&<redacted>&key2=val2&<redacted>"
