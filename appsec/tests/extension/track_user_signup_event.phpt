--TEST--
Track a user signup event and verify the tags in the root span
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\{root_span_get_attributes, rshutdown};
use function datadog\appsec\track_user_signup_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_signup_event("sdkID",
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]);

rshutdown();

echo "root_span_get_attributes():\n";
$attributes = root_span_get_attributes();
ksort($attributes);
print_r($attributes);

var_dump(\DDTrace\get_priority_sampling());
?>
--EXPECTF--
root_span_get_attributes():
Array
(
    [_dd.appsec.enabled] => 1
    [_dd.appsec.events.users.signup.sdk] => true
    [_dd.p.dm] => -5
    [_dd.p.ts] => 02
    [_dd.runtime_family] => php
    [appsec.events.users.signup.email] => noneofyour@business.com
    [appsec.events.users.signup.metadata] => some other metadata
    [appsec.events.users.signup.track] => true
    [appsec.events.users.signup.usr.id] => sdkID
    [appsec.events.users.signup.usr.login] => sdkID
    [appsec.events.users.signup.value] => something
    [process_id] => %d
    [runtime-id] => %s
    [server.business_logic.users.signup] => null
)
int(2)
