--TEST--
Track authenticated user event with anonymization mode, configured through the deprecated variable
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTOMATED_USER_EVENTS_TRACKING=safe
--FILE--
<?php

use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_authenticated_user_event_automated;

include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_authenticated_user_event_automated(
    "automatedID"
);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [usr.id] => anon_b3ddafd7029d645b44fb990eea55b003
    [_dd.appsec.usr.id] => anon_b3ddafd7029d645b44fb990eea55b003
    [_dd.appsec.user.collection_mode] => anonymization
)