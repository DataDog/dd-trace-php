--TEST--
Latest sdk values take priority
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_login_failure_event;
use function datadog\appsec\track_user_login_failure_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_failure_event_automated("login", "automatedID", false, ["value" => "something-from-automated"]);
track_user_login_failure_event("sdkID", true, ["value" => "something-from-sdk"]);
track_user_login_failure_event("otherSdkID", true, ["value" => "something-from-sdk-2"]);
track_user_login_failure_event_automated("otherLogin", "otherAutomatedID", false, ["value" => "something-from-automated-2"]);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [appsec.events.users.login.failure.usr.id] => otherSdkID
    [_dd.appsec.usr.id] => otherAutomatedID
    [_dd.appsec.events.users.login.failure.auto.mode] => identification
    [appsec.events.users.login.failure.usr.login] => otherSdkID
    [_dd.appsec.usr.login] => otherLogin
    [appsec.events.users.login.failure.track] => true
    [appsec.events.users.login.failure.usr.exists] => true
    [server.business_logic.users.login.failure] => null
    [_dd.p.ts] => 02
    [_dd.p.dm] => -5
    [_dd.appsec.events.users.login.failure.sdk] => true
    [appsec.events.users.login.failure.value] => something-from-sdk-2
)
