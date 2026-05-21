--TEST--
Repeated track_authenticated_user_event_automated with same user id dedupes (single usr.id / no extra verdict work)
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=ident
--FILE--
<?php

use function datadog\appsec\testing\root_span_get_meta;
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

echo "root_span_get_meta():\n";
print_r(root_span_get_meta());
?>
--EXPECTF--
root_span_get_meta():
Array
(
    [runtime-id] => %s
    [usr.id] => sameUser
    [_dd.appsec.usr.id] => sameUser
    [_dd.appsec.user.collection_mode] => identification
)
