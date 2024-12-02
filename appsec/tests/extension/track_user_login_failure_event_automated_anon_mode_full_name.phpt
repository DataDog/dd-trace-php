--TEST--
Track automated user login failure with anonymization mode, using the full name as configuration
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=anonymization
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_login_failure_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_failure_event("1234", "5678",
    true,
    [
        "value" => "something",
        "metadata" => "some other metadata",
        "email" => "noneofyour@business.com"
    ]
    , true
);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [appsec.events.users.login.failure.usr.id] => anon_03ac674216f3e15c761ee1a5e255f067
    [appsec.events.users.login.failure.track] => true
    [_dd.appsec.events.users.login.failure.auto.mode] => anonymization
    [appsec.events.users.login.failure.usr.exists] => true
    [appsec.events.users.login.failure.usr.login] => anon_03ac674216f3e15c761ee1a5e255f067
    [_dd.appsec.usr.login] => anon_03ac674216f3e15c761ee1a5e255f067
    [_dd.appsec.usr.id] => anon_03ac674216f3e15c761ee1a5e255f067
)
