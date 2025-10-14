--TEST--
Abort request as a result of rinit, with a non-existent template
--INI--
datadog.appsec.enabled=1
datadog.appsec.log_level=error
--ENV--
DD_APPSEC_HTTP_BLOCKED_TEMPLATE_JSON=tests/extension/templates/missing
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['block', ['status_code' => '500', 'type' => 'json']]], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();

?>
--EXPECTHEADERS--
Content-type: text/html; charset=UTF-8
--EXPECTF--
Warning: %s: %s to open stream: No such file or directory in %s on line %d

Warning: datadog\appsec\testing\rinit(): Datadog blocked the request, but the response has already been partially committed. No action required. Block ID:  in %s on line %d
