--TEST--
Latest sdk values take priority
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_signup_event;
use function datadog\appsec\track_user_signup_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_signup_event_automated("login", "automatedID", ["value" => "something-from-automated"]);
track_user_signup_event("sdkID", ["value" => "something-from-sdk"]);
track_user_signup_event("OtherSdkID", ["value" => "something-from-sdk-2"]);
track_user_signup_event_automated("OtherLogin", "OtherAutomatedID", ["value" => "something-from-automated-2"]);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [appsec.events.users.signup.usr.id] => OtherSdkID
    [_dd.appsec.usr.id] => OtherAutomatedID
    [_dd.appsec.events.users.signup.auto.mode] => identification
    [appsec.events.users.signup.usr.login] => OtherSdkID
    [_dd.appsec.usr.login] => OtherLogin
    [appsec.events.users.signup.track] => true
    [server.business_logic.users.signup] => null
    [_dd.p.ts] => 02
    [_dd.p.dm] => -5
    [_dd.appsec.events.users.signup.sdk] => true
    [appsec.events.users.signup.value] => something-from-sdk-2
)
