--TEST--
Don't block or redirect from v2 user login success event
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
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

\datadog\appsec\v2\track_user_login_success("some login", "some id",
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
string(7) "some id"
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [appsec.events.users.login.success.usr.login] => some login
    [appsec.events.users.login.success.track] => true
    [_dd.appsec.events.users.login.success.sdk] => true
    [_dd.appsec.user.collection_mode] => sdk
    [appsec.events.users.login.success.usr.id] => some id
    [appsec.events.users.login.success.value] => something
    [appsec.events.users.login.success.metadata] => some other metadata
    [appsec.events.users.login.success.email] => noneofyour@business.com
    [usr.id] => some id
    [usr.value] => something
    [usr.metadata] => some other metadata
    [usr.email] => noneofyour@business.com
    [_dd.p.ts] => 02
    [_dd.p.dm] => -5
)
