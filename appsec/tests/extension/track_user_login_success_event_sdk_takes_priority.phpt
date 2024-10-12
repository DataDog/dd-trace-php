--TEST--
When values are set with automated event and with sdk, SDK takes priority
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_login_success_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_success_event("Admin", ["value" => "something-from-sdk"]); //Sdk
track_user_login_success_event("1234", ["value" => "something-from-automated"], true); //Automated

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [usr.id] => Admin
    [_dd.appsec.events.users.login.success.sdk] => true
    [appsec.events.users.login.success.value] => something-from-sdk
    [appsec.events.users.login.success.track] => true
    [_dd.appsec.events.users.login.success.auto.mode] => identification
)
