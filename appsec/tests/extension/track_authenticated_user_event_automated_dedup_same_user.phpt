--TEST--
Repeated track_authenticated_user_event_automated with same user id dedupes (single usr.id / no extra verdict work)
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=ident
--FILE--
<?php

use function datadog\appsec\testing\root_span_get_attributes;
use function datadog\appsec\internal\track_authenticated_user_event_automated;

include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_authenticated_user_event_automated(
    'test',
    'sameUser'
);
track_authenticated_user_event_automated(
    'test',
    'sameUser'
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
    [_dd.appsec.user.collection_mode] => identification
    [_dd.appsec.usr.id] => sameUser
    [process_id] => %d
    [runtime-id] => %s
    [usr.id] => sameUser
)
