--TEST--
Abort request as a result of rinit, with a custom template with block_id
--INI--
datadog.appsec.enabled=1
--ENV--
DD_APPSEC_HTTP_BLOCKED_TEMPLATE_JSON=tests/extension/templates/response_with_block_id.json
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['block', ['status_code' => '500', 'type' => 'json', 'block_id' => 'some-id']]], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();

?>
--EXPECTHEADERS--
Status: 500 Internal Server Error
Content-type: application/json
--EXPECTF--
{"value": "Datadog has blocked this request some-id", "block_id": "some-id"}

Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and presented a static error page. No action required. Block ID: some-id in %s on line %d
