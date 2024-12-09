--TEST--
Track automated user signup event without specifying a mode and verify the tags in the root span
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_signup_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_signup_event_automated("login", "automatedID", []);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [usr.id] => automatedID
    [_dd.appsec.usr.id] => automatedID
    [_dd.appsec.events.users.signup.auto.mode] => identification
    [appsec.events.users.signup.usr.login] => login
    [_dd.appsec.usr.login] => login
    [appsec.events.users.signup.track] => true
    [appsec.events.users.signup] => null
)
