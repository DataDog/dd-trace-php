--TEST--
Don't block or redirect from v2 user login success event
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_attributes;
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
var_dump($c[0][1][0]['usr.id']);

echo "root_span_get_attributes():\n";
$attributes = root_span_get_attributes();
ksort($attributes);
print_r($attributes);

$helper->finished_with_commands();
?>
--EXPECTF--
usr.id:
string(7) "some id"
root_span_get_attributes():
Array
(
    [_dd.appsec.events.users.login.success.sdk] => true
    [_dd.appsec.user.collection_mode] => sdk
    [_dd.p.dm] => -5
    [_dd.p.ts] => 02
    [appsec.events.users.login.success.email] => noneofyour@business.com
    [appsec.events.users.login.success.metadata] => some other metadata
    [appsec.events.users.login.success.track] => true
    [appsec.events.users.login.success.usr.id] => some id
    [appsec.events.users.login.success.usr.login] => some login
    [appsec.events.users.login.success.value] => something
    [process_id] => %d
    [runtime-id] => %s
    [usr.email] => noneofyour@business.com
    [usr.id] => some id
    [usr.metadata] => some other metadata
    [usr.value] => something
)
