--TEST--
Abort request as a result of rshutdown, using defaults
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['record', new ArrayObject()]], ['{"found":"attack"}','{"another":"attack"}']])),
    response_list(response_request_shutdown([[['block', new ArrayObject()]], ['{"yet another":"attack"}'], true]))
], ['continuous' => false]);

rinit();
$helper->get_commands(); //ignore
rshutdown();
?>
--EXPECTHEADERS--
Status: 403 Forbidden
Content-type: application/json
--EXPECTF--
{"errors":[{"title":"You've been blocked","detail":"Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}],"security_response_id":""}
