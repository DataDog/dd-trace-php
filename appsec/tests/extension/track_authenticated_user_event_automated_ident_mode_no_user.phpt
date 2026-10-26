--TEST--
Track authenticated user event with identification mode and empty user ID.
--INI--
extension=ddtrace.so
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=identification
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_attributes;
use function datadog\appsec\internal\track_authenticated_user_event_automated;

include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_authenticated_user_event_automated(
    'test',
    ""
);

echo "root_span_get_attributes():\n";
$attributes = root_span_get_attributes();
ksort($attributes);
print_r($attributes);
?>
--EXPECTF--
root_span_get_attributes():
Array
(
    [process_id] => %d
    [runtime-id] => %s
)
