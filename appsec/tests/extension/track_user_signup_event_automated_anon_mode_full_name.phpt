--TEST--
Track automated user signup with anonymization mode, using the full name as configuration
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=anonymization
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_signup_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_signup_event_automated("login", "automatedID",
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [appsec.events.users.signup.usr.id] => anon_b3ddafd7029d645b44fb990eea55b003
    [_dd.appsec.usr.id] => anon_b3ddafd7029d645b44fb990eea55b003
    [_dd.appsec.events.users.signup.auto.mode] => anonymization
    [appsec.events.users.signup.usr.login] => anon_428821350e9691491f616b754cd8315f
    [_dd.appsec.usr.login] => anon_428821350e9691491f616b754cd8315f
    [appsec.events.users.signup.track] => true
    [server.business_logic.users.signup] => null
    [_dd.p.ts] => 02
)
