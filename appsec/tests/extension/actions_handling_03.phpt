--TEST--
When there are multiple block action, the first one is the one used
--INI--
datadog.appsec.enabled=1
--ENV--
DD_APPSEC_HTTP_BLOCKED_TEMPLATE_HTML=tests/extension/templates/response.html
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []], ['block', ['status_code' => '500', 'type' => 'html']], ['block', ['status_code' => '501', 'type' => 'json']]], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();

?>
--EXPECTHEADERS--
Status: 500 Internal Server Error
Content-type: text/html;charset=UTF-8
--EXPECTF--
<html lang=\"en\"><head><title>You've been blocked</title></head><body><main><p>Sorry!</p></main><footer><p>Security provided</p></footer></body></html>

Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and presented a static error page in %s on line %d
