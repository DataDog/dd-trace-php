--TEST--
Redirect request as a result of rinit, with invalid status_code and valid location
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['redirect', ['status_code' => '400', 'location' => 'http://alex.com']]], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();

?>
Some content here which should not be displayed
--EXPECTHEADERS--
Status: 303 See Other
Content-type: text/html; charset=UTF-8
--EXPECTF--
Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and attempted a redirection to http://alex.com in %s on line %s