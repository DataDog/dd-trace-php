--TEST--
Track an automated user login success event with an empty user and verify the tags
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_attributes;
use function datadog\appsec\internal\track_user_login_success_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_success_event_automated('test', "login", "",
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
    [_dd.appsec.events.users.login.success.auto.mode] => identification
    [_dd.appsec.usr.login] => login
    [_dd.p.dm] => -5
    [_dd.p.ts] => 02
    [appsec.events.users.login.success.track] => true
    [appsec.events.users.login.success.usr.login] => login
    [process_id] => %d
    [runtime-id] => %s
    [server.business_logic.users.login.success] => null
)
