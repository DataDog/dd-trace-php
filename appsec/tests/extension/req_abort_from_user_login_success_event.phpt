--TEST--
Track a user login success event and verify the tags in the root span
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_login_success_event;
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.85.0');

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['block', ['useless' => 'value', 'status_code' => '404', 'not' => 'used', 'type' => 'json', 'another' => 'unused']]], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();


track_user_login_success_event("Admin",
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]);

echo "SHOULD NOT BE REACHED\n";
?>
--EXPECTHEADERS--
Status: 404 Not Found
Content-type: application/json
--EXPECTF--
{"errors": [{"title": "You've been blocked", "detail": "Sorry, you cannot access this page. Please contact the customer service team. Security provided by Datadog."}], "security_response_id": ""}
Warning: datadog\appsec\track_user_login_success_event(): Datadog blocked the request and presented a static error page. No action required. Security Response ID:  in %s on line %d
