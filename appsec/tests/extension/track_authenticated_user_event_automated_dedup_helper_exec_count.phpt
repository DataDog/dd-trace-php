--TEST--
track_authenticated_user_event_automated dedupes helper request_exec for the same user id
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--ENV--
DD_APPSEC_ENABLED=1
DD_APPSEC_AUTO_USER_INSTRUMENTATION_MODE=ident
--FILE--
<?php

use function datadog\appsec\internal\track_authenticated_user_event_automated;
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/ddtrace_version.php';
include __DIR__ . '/inc/mock_helper.php';

ddtrace_version_at_least('0.79.0');

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['ok', []]]])),
], ['continuous' => true]);

rinit();
$helper->get_commands(); // drain client_init + request_init (and any startup traffic)

track_authenticated_user_event_automated('test', 'sameUser');
track_authenticated_user_event_automated('test', 'sameUser');

$commands = $helper->get_commands();
$n = 0;
foreach ($commands as $c) {
    if (is_array($c) && isset($c[0]) && $c[0] === 'request_exec') {
        $n++;
    }
}
echo "request_exec messages: {$n}\n";

$helper->finished_with_commands();
?>
--EXPECT--
request_exec messages: 1
