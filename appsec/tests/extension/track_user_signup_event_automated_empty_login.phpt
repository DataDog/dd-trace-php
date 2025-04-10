--TEST--
Track an automated user login success event with an empty user login and verify the logs
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\track_user_signup_event_automated;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_signup_event_automated("", "automatedID",
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]);

require __DIR__ . '/inc/logging.php';
match_log("/Unexpected empty user login/");
?>
--EXPECTF--
found message in log matching /Unexpected empty user login/
