--TEST--
When values are set with automated event and with sdk, SDK takes priority on extended mode
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTOMATED_USER_EVENTS_TRACKING=extended
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_login_failure_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_failure_event("Admin", true, ["value" => "something-from-sdk"]); //Sdk
track_user_login_failure_event("1234", false, ["value" => "something-from-automated"], true); //Automated

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [appsec.events.users.login.failure.usr.id] => Admin
    [appsec.events.users.login.failure.track] => true
    [_dd.appsec.events.users.login.failure.sdk] => true
    [appsec.events.users.login.failure.usr.exists] => true
    [appsec.events.users.login.failure.value] => something-from-sdk
    [_dd.appsec.events.users.login.failure.auto.mode] => extended
)
