--TEST--
Abort request as a result of rinit, using defaults
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['block', new ArrayObject()]], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();

?>
--EXPECTHEADERS--
Status: 403 Forbidden
Content-type: application/json
--EXPECTF--
{"errors": [{"title": "You've been blocked", "detail": "Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}]}
Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and presented a static error page - block_id:  in %s on line %d
