--TEST--
Avoid track authenticated user event gets fired multiple times
--INI--
extension=ddtrace.so
--ENV--
DD_APPSEC_ENABLED=1
--FILE--
<?php

use function datadog\appsec\testing\root_span_get_meta;
use function datadog\appsec\track_authenticated_user_event_automated;

include __DIR__ . '/inc/ddtrace_version.php';

ddtrace_version_at_least('0.79.0');

track_authenticated_user_event_automated(
    "first"
);

track_authenticated_user_event_automated(
    "second"
);

$meta = root_span_get_meta();
var_dump($meta['_dd.appsec.usr.id']);
?>
--EXPECTF--
string(5) "first"