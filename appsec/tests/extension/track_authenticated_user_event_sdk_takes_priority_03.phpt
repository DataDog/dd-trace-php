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
    "automatedID"
);
track_authenticated_user_event(
    "ID",
    [ "metadata" => "someValue" ]
);
track_authenticated_user_event(
    "otherID",
    [ "metadata" => "otherValue" ]
);
track_authenticated_user_event_automated(
    "otherAutomatedID"
);

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [usr.id] => otherID
    [_dd.appsec.usr.id] => otherAutomatedID
    [_dd.appsec.user.collection_mode] => sdk
    [usr.metadata] => otherValue
)