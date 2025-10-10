--TEST--
Abort request as a result of rinit, with custom status code and content type
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['block', ['useless' => 'value', 'status_code' => '404', 'not' => 'used', 'type' => 'json', 'another' => 'unused']]], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();

?>
--EXPECTHEADERS--
Status: 404 Not Found
Content-type: application/json
--EXPECTF--
{"errors": [{"title": "You've been blocked", "detail": "Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}]}
Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and presented a static error page - block_id:  in %s on line %d
