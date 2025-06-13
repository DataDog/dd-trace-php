--TEST--
Don't block or redirect from user login success event
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
    response_list(response_request_exec([[['ok', []]]])),
], ['continuous' => true]);

rinit();
$helper->get_commands(); // Ignore

track_user_login_success_event("Admin",
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]);

$c = $helper->get_commands();
echo "usr.id:\n";
var_dump($c[0][1][1]['usr.id']);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());

$helper->finished_with_commands();
?>
--EXPECTF--
usr.id:
string(5) "Admin"
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [usr.id] => Admin
    [appsec.events.users.login.success.usr.login] => Admin
    [_dd.appsec.events.users.login.success.sdk] => true
    [appsec.events.users.login.success.value] => something
    [appsec.events.users.login.success.metadata] => some other metadata
    [appsec.events.users.login.success.email] => noneofyour@business.com
    [appsec.events.users.login.success.track] => true
    [server.business_logic.users.login.success] => null
    [_dd.p.ts] => 02
    [_dd.p.dm] => -4
)
