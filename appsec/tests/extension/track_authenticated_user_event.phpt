--TEST--
Track authenticated user event with default mode and verify the tags in the root span
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php

use function datadog\appsec\testing\root_span_get_attributes;
use function datadog\appsec\track_authenticated_user_event;

include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_authenticated_user_event("ID", [
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
    [_dd.appsec.user.collection_mode] => sdk
    [process_id] => %d
    [runtime-id] => %s
    [usr.email] => noneofyour@business.com
    [usr.id] => ID
    [usr.metadata] => some other metadata
    [usr.value] => something
)