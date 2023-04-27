--TEST--
Track a custom event and verify the contents of the root span
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_custom_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_custom_event("myevent",
[
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
    [appsec.events.myevent.track] => true
    [appsec.events.myevent.value] => something
    [appsec.events.myevent.metadata] => some other metadata
    [appsec.events.myevent.email] => noneofyour@business.com
)
