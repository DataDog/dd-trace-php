--TEST--
Latest sdk values take priority
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_signup_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_signup_event("1234", "5678", ["value" => "something-from-automated"], true); //Automated
track_user_signup_event("Admin", "login", ["value" => "something-from-sdk"], false); //Sdk
track_user_signup_event("OtherUser", "Otherlogin", ["value" => "something-from-sdk-2"], false); //Sdk
track_user_signup_event("456", "789", ["value" => "something-from-automated-2"], true); //Automated

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [usr.id] => OtherUser
    [_dd.appsec.events.users.signup.auto.mode] => identification
    [appsec.events.users.signup.track] => true
    [_dd.appsec.events.users.signup.sdk] => true
    [appsec.events.users.signup.value] => something-from-sdk-2
    [_dd.appsec.usr.id] => OtherUser
    [appsec.events.users.signup.usr.id] => OtherUser
    [_dd.appsec.usr.login] => OtherLogin
    [appsec.events.users.signup.usr.login] => OtherLogin
)
