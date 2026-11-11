--TEST--
Reconnect to the helper after a skipped request shutdown
--DESCRIPTION--
If request shutdown is skipped, the helper is still waiting for that request
to finish. AppSec must reconnect before sending another request_init. Closing
the old connection must not increase the connection failure count.

Check both recovery paths: starting another request directly, and running the
next PHP request's RINIT first. The latter must close the old connection even
if the new request has not yet contacted the helper.
--INI--
datadog.appsec.enabled=1
display_errors=0
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,req_lifecycle_rinit,backoff_status,is_connected_to_helper};

include __DIR__ . '/inc/mock_helper.php';

$empty_obj = new ArrayObject();
$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response('client_shutdown', null)),
    response_list(response_client_init(
        ['ok', phpversion('ddtrace'), [], $empty_obj, $empty_obj, null])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response('client_shutdown', null)),
    response_list(response_client_init(
        ['ok', phpversion('ddtrace'), [], $empty_obj, $empty_obj, null])),
    response_list(response_request_init([[['ok', []]]])),
], ['continuous' => true]);

// No rshutdown() between requests: request_init itself must reconnect.
rinit();
rinit();
echo "direct init: ", implode(',', array_column($helper->get_commands(), 0)), "\n";
echo "failed_count: ", backoff_status()['failed_count'], "\n";

// Simulate the next PHP request's RINIT. Disable root spans above because
// these requests share a heap; a real request would have freed the old span.
req_lifecycle_rinit();
echo "connected after RINIT: ";
var_dump(is_connected_to_helper());

rinit();
echo "init after RINIT: ", implode(',', array_column($helper->get_commands(), 0)), "\n";
echo "connected: ";
var_dump(is_connected_to_helper());
echo "failed_count: ", backoff_status()['failed_count'], "\n";
$helper->finished_with_commands();
?>
--EXPECT--
direct init: client_init,request_init,client_shutdown,client_init,request_init
failed_count: 0
connected after RINIT: bool(false)
init after RINIT: client_shutdown,client_init,request_init
connected: bool(true)
failed_count: 0
