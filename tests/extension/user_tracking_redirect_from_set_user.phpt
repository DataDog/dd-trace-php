--TEST--
Redirect from DDTrace\set_user
--INI--
extension=ddtrace.so
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_login_success_event;
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.84.0');

$helper = Helper::createInitedRun([
    response_list(response_request_init(['ok', []])),
    response_list(response_request_exec(['redirect', ['status_code' => '303', 'location' => 'https://datadoghq.com'], []])),
], ['continuous' => true]);

rinit();
$helper->get_commands(); // Ignore

DDTrace\set_user("Admin",
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]);

echo "SHOULD NOT BE REACHED\n";
?>
--EXPECTHEADERS--
Status: 303 See Other
Content-type: text/html; charset=UTF-8
--EXPECTF--
Warning: DDTrace\set_user(): Datadog blocked the request and attempted a redirection to https://datadoghq.com in %s on line %d
