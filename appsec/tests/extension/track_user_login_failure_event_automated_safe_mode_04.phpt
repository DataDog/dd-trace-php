--TEST--
Safe mode allows uuid v1
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTOMATED_USER_EVENTS_TRACKING=safe
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_login_failure_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_failure_event("85e37758-0b85-11ee-be56-0242ac120002", true, [], true);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [appsec.events.users.login.failure.usr.id] => 85e37758-0b85-11ee-be56-0242ac120002
    [appsec.events.users.login.failure.track] => true
    [_dd.appsec.events.users.login.failure.auto.mode] => safe
    [appsec.events.users.login.failure.usr.exists] => true
)
