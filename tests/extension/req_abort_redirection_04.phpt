--TEST--
Redirect request as a result of rinit, with invalid status_code and invalid location
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init(['redirect', ['status_code' => '404', 'location' => ''], ['{"found":"attack"}','{"another":"attack"}']])),
]);

rinit();

?>
Some content here which should be displayed
--EXPECTHEADERS--
Content-type: text/html; charset=UTF-8
--EXPECTF--
Warning: datadog\appsec\testing\rinit(): [ddappsec] Failing to redirect: No location set in %s on line %s
Some content here which should be displayed