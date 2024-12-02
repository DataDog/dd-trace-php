--TEST--
Track a user login success event when no root span is available and verify the logs
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_user_login_success_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_user_login_success_event("Admin", "login",
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]);


require __DIR__ . '/inc/logging.php';
match_log("/No root span available on request init/");
?>
--EXPECTF--
found message in log matching /No root span available on request init/
