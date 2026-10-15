--TEST--
Track automated user sign up event with anonymization mode and verify the tags in the root span
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=anon
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_attributes;
use function datadog\appsec\internal\track_user_signup_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_signup_event_automated('test', "login", "automatedID",
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]);

echo "root_span_get_attributes():\n";
$attributes = root_span_get_attributes();
ksort($attributes);
print_r($attributes);
?>
--EXPECTF--
root_span_get_attributes():
Array
(
    [_dd.appsec.events.users.signup.auto.mode] => anonymization
    [_dd.appsec.usr.id] => anon_b3ddafd7029d645b44fb990eea55b003
    [_dd.appsec.usr.login] => anon_428821350e9691491f616b754cd8315f
    [_dd.p.dm] => -5
    [_dd.p.ts] => 02
    [appsec.events.users.signup.track] => true
    [appsec.events.users.signup.usr.id] => anon_b3ddafd7029d645b44fb990eea55b003
    [appsec.events.users.signup.usr.login] => anon_428821350e9691491f616b754cd8315f
    [process_id] => %d
    [runtime-id] => %s
    [server.business_logic.users.signup] => null
)
