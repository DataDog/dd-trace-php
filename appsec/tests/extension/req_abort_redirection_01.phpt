--TEST--
Redirect request as a result of rinit, with custom status_code and location
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['redirect', ['status_code' => '301', 'location' => 'http://alex.com', 'not-relevant' => 'field']]], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();

?>
Some content here which should not be displayed
--EXPECTHEADERS--
Status: 301 Moved Permanently
Content-type: text/html; charset=UTF-8
--EXPECTF--
Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and attempted a redirection to http://alex.com - block_id:  in %s on line %s