--TEST--
When values are set with automated event and with sdk, SDK takes priority on identification mode
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=ident
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_attributes;
use function datadog\appsec\track_user_login_failure_event;
use function datadog\appsec\internal\track_user_login_failure_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_failure_event_automated('test', "login", "automatedID", false, ["value" => "something-from-automated"]);
track_user_login_failure_event("sdkID", true, ["value" => "something-from-sdk"]);

echo "root_span_get_attributes():\n";
$attributes = root_span_get_attributes();
ksort($attributes);
print_r($attributes);
?>
--EXPECTF--
Deprecated: Function datadog\appsec\track_user_login_failure_event() is deprecated in %s on line %d
root_span_get_attributes():
Array
(
    [_dd.appsec.events.users.login.failure.auto.mode] => identification
    [_dd.appsec.events.users.login.failure.sdk] => true
    [_dd.appsec.usr.id] => automatedID
    [_dd.appsec.usr.login] => login
    [_dd.p.dm] => -5
    [_dd.p.ts] => 02
    [appsec.events.users.login.failure.track] => true
    [appsec.events.users.login.failure.usr.exists] => true
    [appsec.events.users.login.failure.usr.id] => sdkID
    [appsec.events.users.login.failure.usr.login] => sdkID
    [appsec.events.users.login.failure.value] => something-from-sdk
    [process_id] => %d
    [runtime-id] => %s
    [server.business_logic.users.login.failure] => null
)
