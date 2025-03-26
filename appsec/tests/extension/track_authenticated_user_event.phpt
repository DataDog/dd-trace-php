--TEST--
Track authenticated user event with default mode and verify the tags in the root span
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php

use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_authenticated_user_event;

include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_authenticated_user_event("ID", [
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [usr.id] => ID
    [_dd.appsec.user.collection_mode] => sdk
    [usr.value] => something
    [usr.metadata] => some other metadata
    [usr.email] => noneofyour@business.com
)