--TEST--
Track automated user login success event with identification mode, using the full name as configuration
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=identification
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_login_success_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_success_event("1234", ['email' => 'some@email.com'], true);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [usr.id] => 1234
    [_dd.appsec.events.users.login.success.auto.mode] => identification
    [appsec.events.users.login.success.track] => true
)
