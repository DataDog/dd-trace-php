--TEST--
Request abort during the middle of the request without specifying accepted content type (default)
--FILE--
<?php
\datadog\appsec\testing\abort_static_page();
?>
THIS SHOULD NOT BE REACHED
--EXPECTHEADERS--
Status: 403 Forbidden
Content-type: application/json
--EXPECTF--
{"errors": [{"title": "You've been blocked", "detail": "Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}]}
Warning: datadog\appsec\testing\abort_static_page(): Datadog blocked the request and presented a static error page - block_id:  in %s on line %d
