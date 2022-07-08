--TEST--
Verify the user agent is added to the root span on serialization.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
HTTP_USER_AGENT=dd_trace_user_agent
--FILE--
<?php
DDTrace\start_span();
DDTrace\close_span(0);
$span = dd_trace_serialize_closed_spans();
var_dump($span[0]["meta"]["http.useragent"]);
?>
--EXPECTF--
string(19) "dd_trace_user_agent"
