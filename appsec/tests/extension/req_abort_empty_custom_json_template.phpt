--TEST--
Abort request as a result of rinit, with an empty template
--INI--
datadog.appsec.enabled=1
--ENV--
DD_APPSEC_HTTP_BLOCKED_TEMPLATE_JSON=tests/extension/templates/empty_response
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init(['block', ['status_code' => '500', 'type' => 'json'], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();

?>
--EXPECTHEADERS--
Status: 500 Internal Server Error
Content-type: application/json
--EXPECTF--
{"errors": [{"title": "You've been blocked", "detail": "Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}]}
Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and presented a static error page in %s on line %d
