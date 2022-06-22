--TEST--
root span with http.url and obfuscated query string
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die('skip: obfuscation only available on PHP >= 7'); ?>

--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_HTTP_URL_QUERY_PARAM_ALLOWED=*
HTTPS=off
HTTP_HOST=localhost:9999
SCRIPT_NAME=/foo.php
REQUEST_URI=/foo?key1=val1&token=a0b21ce2-006f-4cc6-95d5-d7b550698482&key2=val2&password=something&key=%7B%20%22sign%22%3A%20%22%7B0x03cb9f67%2C0xdbbc%2C0x4cb8%2C%7B0xb9%2C0x66%2C0x32%2C0x99%2C0x51%2C0xe1%2C0x09%2C0x34%7D%7D%22%7D
QUERY_STRING=key1=val1&token=a0b21ce2-006f-4cc6-95d5-d7b550698482&key2=val2&password=something&key=%7B%20%22sign%22%3A%20%22%7B0x03cb9f67%2C0xdbbc%2C0x4cb8%2C%7B0xb9%2C0x66%2C0x32%2C0x99%2C0x51%2C0xe1%2C0x09%2C0x34%7D%7D%22%7D
METHOD=GET
--GET--
key1=val1&token=a0b21ce2-006f-4cc6-95d5-d7b550698482&key2=val2&password=something&key=%7B%20%22sign%22%3A%20%22%7B0x03cb9f67%2C0xdbbc%2C0x4cb8%2C%7B0xb9%2C0x66%2C0x32%2C0x99%2C0x51%2C0xe1%2C0x09%2C0x34%7D%7D%22%7D
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']["http.url"]);
?>
--EXPECT--
string(87) "https://localhost:9999/foo?key1=val1&<redacted>&key2=val2&<redacted>&key={ "<redacted>}"
