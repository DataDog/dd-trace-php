--TEST--
Redirect request as a result of rinit, with invalid status_code and invalid location
--DESCRIPTION--
Since location is empty, it defaults to block request with default behaviour
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['redirect', ['status_code' => '404', 'location' => '']]], ['{"found":"attack"}','{"another":"attack"}']])),
]);

rinit();

?>
Some content here which should be displayed
--EXPECTHEADERS--
Status: 403 Forbidden
Content-type: application/json
--EXPECTF--
{"errors":[{"title":"You've been blocked","detail":"Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}],"security_response_id":""}
Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and presented a static error page. No action required. Security Response ID:  in %s on line %d