--TEST--
When values are set with automated event and with sdk, SDK takes priority
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

track_user_signup_event("sdkID", ["value" => "something-from-sdk"]);
track_user_signup_event_automated("login", "automatedID", ["value" => "something-from-automated"]);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [appsec.events.users.signup.usr.id] => sdkID
    [appsec.events.users.signup.usr.login] => sdkID
    [_dd.appsec.events.users.signup.sdk] => true
    [appsec.events.users.signup.value] => something-from-sdk
    [appsec.events.users.signup.track] => true
    [server.business_logic.users.signup] => null
    [_dd.p.ts] => 02
    [_dd.appsec.usr.id] => automatedID
    [_dd.appsec.events.users.signup.auto.mode] => identification
    [_dd.appsec.usr.login] => login
)
