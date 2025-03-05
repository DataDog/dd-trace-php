--TEST--
Track authenticated user event and check that sdk takes priority.
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=ident
--FILE--
<?php

use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_authenticated_user_event_automated;
use function datadog\appsec\track_authenticated_user_event;

include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_authenticated_user_event_automated(
    "automatedID",
);
track_authenticated_user_event(
    "ID",
    [ "metadata" => "someValue" ]
);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [usr.id] => ID
    [_dd.appsec.usr.id] => automatedID
    [_dd.appsec.user.collection.mode] => sdk
    [usr.metadata] => someValue
)