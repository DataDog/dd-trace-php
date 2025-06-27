--TEST--
Track automated user sign up event with identification mode, configured through the deprecated variable
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTOMATED_USER_EVENTS_TRACKING=extended
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_signup_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_signup_event_automated("login", "automatedID", ['email' => 'some@email.com']);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [appsec.events.users.signup.usr.id] => automatedID
    [_dd.appsec.usr.id] => automatedID
    [_dd.appsec.events.users.signup.auto.mode] => identification
    [appsec.events.users.signup.usr.login] => login
    [_dd.appsec.usr.login] => login
    [appsec.events.users.signup.track] => true
    [server.business_logic.users.signup] => null
    [_dd.p.ts] => 02
    [_dd.p.dm] => -5
)
