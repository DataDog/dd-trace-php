--TEST--
Track a user login success event and verify the tags in the root span
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\{root_span_get_meta, root_span_get_metrics, rshutdown};
use function datadog\appsec\track_user_login_success_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_success_event("sdkID",
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]);

rshutdown();

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());

echo "root_span_get_metrics():\n";
print_r(root_span_get_metrics());

var_dump(\DDTrace\get_priority_sampling());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [usr.id] => sdkID
    [appsec.events.users.login.success.usr.login] => sdkID
    [_dd.appsec.events.users.login.success.sdk] => true
    [appsec.events.users.login.success.value] => something
    [appsec.events.users.login.success.metadata] => some other metadata
    [appsec.events.users.login.success.email] => noneofyour@business.com
    [appsec.events.users.login.success.track] => true
    [server.business_logic.users.login.success] => null
    [_dd.p.ts] => 02
    [_dd.p.dm] => -4
    [_dd.runtime_family] => php
)
root_span_get_metrics():
Array
(
    [%s] => %d
    [_dd.appsec.enabled] => 1
)
int(2)
