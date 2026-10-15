--TEST--
Track an automated user login failure event without user provided and verify the tags in the root span
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_attributes;
use function datadog\appsec\internal\track_user_login_failure_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_failure_event_automated('test', "login", "", false, []);

echo "root_span_get_attributes():\n";
$attributes = root_span_get_attributes();
ksort($attributes);
print_r($attributes);
?>
--EXPECTF--
root_span_get_attributes():
Array
(
    [_dd.appsec.events.users.login.failure.auto.mode] => identification
    [_dd.appsec.usr.login] => login
    [_dd.p.dm] => -5
    [_dd.p.ts] => 02
    [appsec.events.users.login.failure.track] => true
    [appsec.events.users.login.failure.usr.exists] => false
    [appsec.events.users.login.failure.usr.login] => login
    [process_id] => %d
    [runtime-id] => %s
    [server.business_logic.users.login.failure] => null
)
