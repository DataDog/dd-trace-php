--TEST--
Redirections can have a block id place holder that is replaced with given
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['redirect', ['status_code' => 'bad', 'location' => 'http://alex.com?security_response_id=[security_response_id]', 'security_response_id' => '00000000-0000-0000-0000-000000000000']]], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();

?>
Some content here which should not be displayed
--EXPECTHEADERS--
Status: 303 See Other
Content-type: text/html; charset=UTF-8
--EXPECTF--
Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and attempted a redirection to http://alex.com?security_response_id=00000000-0000-0000-0000-000000000000. No action required. Security Response ID: 00000000-0000-0000-0000-000000000000 in %s on line %s

