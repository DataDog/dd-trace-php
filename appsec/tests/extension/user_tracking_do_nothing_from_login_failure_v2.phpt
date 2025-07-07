--TEST--
Don't block or redirect from user v2 login failure event
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

\datadog\appsec\v2\track_user_login_failure("some login", false,
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]);

$c = $helper->get_commands();

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());

$helper->finished_with_commands();
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [appsec.events.users.login.failure.usr.login] => some login
    [appsec.events.users.login.failure.usr.exists] => false
    [appsec.events.users.login.failure.track] => true
    [_dd.appsec.events.users.login.failure.sdk] => true
    [appsec.events.users.login.failure.value] => something
    [appsec.events.users.login.failure.metadata] => some other metadata
    [appsec.events.users.login.failure.email] => noneofyour@business.com
    [_dd.p.ts] => 02
    [_dd.p.dm] => -5
)