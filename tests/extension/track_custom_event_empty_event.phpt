--TEST--
Track a custom event with an empty event name and verify the logs
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_custom_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_custom_event("", array());

require __DIR__ . '/inc/logging.php';
match_log("/Unexpected empty event name/");
?>
--EXPECTF--
found message in log matching /Unexpected empty event name/
