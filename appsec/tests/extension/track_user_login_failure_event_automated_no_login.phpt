--TEST--
Track an automated user login failure event without login provided and verify the tags in the root span
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_login_failure_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_failure_event_automated("", "automatedID", false, []);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [appsec.events.users.login.failure.usr.id] => automatedID
    [_dd.appsec.usr.id] => automatedID
    [_dd.appsec.events.users.login.failure.auto.mode] => identification
    [appsec.events.users.login.failure.track] => true
    [appsec.events.users.login.failure.usr.exists] => false
    [server.business_logic.users.login.failure] => null
)
