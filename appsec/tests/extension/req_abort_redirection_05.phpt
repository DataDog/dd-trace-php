--TEST--
Redirect request as a result of rinit, with valid status_code and missing location
--DESCRIPTION--
Since location is missing, it defaults to block request with default behaviour
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['redirect', ['status_code' => '404']]], ['{"found":"attack"}','{"another":"attack"}']])),
]);

rinit();

?>
Some content here which should not be displayed
--EXPECTHEADERS--
Status: 403 Forbidden
Content-type: application/json
--EXPECTF--
{"errors": [{"title": "You've been blocked", "detail": "Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog.", "block_id": ""}]}
Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and presented a static error page - block_id:  in %s on line %d