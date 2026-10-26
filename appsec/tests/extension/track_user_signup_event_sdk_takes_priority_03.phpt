--TEST--
Latest sdk values take priority
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_attributes;
use function datadog\appsec\track_user_signup_event;
use function datadog\appsec\internal\track_user_signup_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_signup_event_automated('test', "login", "automatedID", ["value" => "something-from-automated"]);
track_user_signup_event("sdkID", ["value" => "something-from-sdk"]);
track_user_signup_event("OtherSdkID", ["value" => "something-from-sdk-2"]);
track_user_signup_event_automated('test', "OtherLogin", "OtherAutomatedID", ["value" => "something-from-automated-2"]);

echo "root_span_get_attributes():\n";
$attributes = root_span_get_attributes();
ksort($attributes);
print_r($attributes);
?>
--EXPECTF--
root_span_get_attributes():
Array
(
    [_dd.appsec.events.users.signup.auto.mode] => identification
    [_dd.appsec.events.users.signup.sdk] => true
    [_dd.appsec.usr.id] => OtherAutomatedID
    [_dd.appsec.usr.login] => OtherLogin
    [_dd.p.dm] => -5
    [_dd.p.ts] => 02
    [appsec.events.users.signup.track] => true
    [appsec.events.users.signup.usr.id] => OtherSdkID
    [appsec.events.users.signup.usr.login] => OtherSdkID
    [appsec.events.users.signup.value] => something-from-sdk-2
    [process_id] => %d
    [runtime-id] => %s
    [server.business_logic.users.signup] => null
)
