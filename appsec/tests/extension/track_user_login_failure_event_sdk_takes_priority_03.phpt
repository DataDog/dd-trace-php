--TEST--
Latest sdk values take priority
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_attributes;
use function datadog\appsec\track_user_login_failure_event;
use function datadog\appsec\internal\track_user_login_failure_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_failure_event_automated('test', "login", "automatedID", false, ["value" => "something-from-automated"]);
track_user_login_failure_event("sdkID", true, ["value" => "something-from-sdk"]);
track_user_login_failure_event("otherSdkID", true, ["value" => "something-from-sdk-2"]);
track_user_login_failure_event_automated('test', "otherLogin", "otherAutomatedID", false, ["value" => "something-from-automated-2"]);

echo "root_span_get_attributes():\n";
$attributes = root_span_get_attributes();
ksort($attributes);
print_r($attributes);
?>
--EXPECTF--
Deprecated: Function datadog\appsec\track_user_login_failure_event() is deprecated in %s on line %d

Deprecated: Function datadog\appsec\track_user_login_failure_event() is deprecated in %s on line %d
root_span_get_attributes():
Array
(
    [_dd.appsec.events.users.login.failure.auto.mode] => identification
    [_dd.appsec.events.users.login.failure.sdk] => true
    [_dd.appsec.usr.id] => otherAutomatedID
    [_dd.appsec.usr.login] => otherLogin
    [_dd.p.dm] => -5
    [_dd.p.ts] => 02
    [appsec.events.users.login.failure.track] => true
    [appsec.events.users.login.failure.usr.exists] => true
    [appsec.events.users.login.failure.usr.id] => otherSdkID
    [appsec.events.users.login.failure.usr.login] => otherSdkID
    [appsec.events.users.login.failure.value] => something-from-sdk-2
    [process_id] => %d
    [runtime-id] => %s
    [server.business_logic.users.login.failure] => null
)
