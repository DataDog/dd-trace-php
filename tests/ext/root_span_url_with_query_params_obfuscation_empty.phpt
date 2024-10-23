--TEST--
Root span with http.url and unobfuscated query string with empty regex
--INI--
datadog.trace.obfuscation_query_string_regexp=
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
HTTPS=off
HTTP_HOST=localhost:9999
SCRIPT_NAME=/foo.php
REQUEST_URI=/users?application_key=123
QUERY_STRING=application_key=123
METHOD=GET
--GET--
application_key=123
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span();
$spans = dd_trace_serialize_closed_spans();
var_dump($spans[0]['meta']["http.url"]);
?>
--EXPECT--
string(48) "https://localhost:9999/users?application_key=123"
