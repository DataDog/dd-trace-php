--TEST--
Track a custom event and verify the contents of the root span
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php
use function datadog\appsec\testing\root_span_get_attributes;
use function datadog\appsec\track_custom_event;
include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_custom_event("myevent",
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
    [_dd.p.dm] => -5
    [_dd.p.ts] => 02
    [appsec.events.myevent.email] => noneofyour@business.com
    [appsec.events.myevent.metadata] => some other metadata
    [appsec.events.myevent.track] => true
    [appsec.events.myevent.value] => something
    [process_id] => %d
    [runtime-id] => %s
)
