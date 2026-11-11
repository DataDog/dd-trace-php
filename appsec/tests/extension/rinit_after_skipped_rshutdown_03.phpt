--TEST--
The automatic request init after a skipped request shutdown drops a connection whose helper is still inside the previous request
--DESCRIPTION--
The first request sends request_init and its RSHUTDOWN is skipped, so the
helper stays inside that request. The automatic RINIT of the next request must
notice, forget the previous request's state and drop the connection locally,
without counting that toward the connection backoff. The next helper request
then starts on a new client.
The test runs both requests in one process, so a root span forgotten by the
second one would stay alive and be reported as a leak. In a real request the
old span went away with the old request heap, so no root span is generated
here.
--INI--
datadog.appsec.enabled=1
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,req_lifecycle_rinit,backoff_status,is_connected_to_helper};

include __DIR__ . '/inc/mock_helper.php';

$empty_obj = new ArrayObject();
$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_client_init(
        ['ok', phpversion('ddtrace'), [], $empty_obj, $empty_obj, null])),
    response_list(response_request_init([[['ok', []]]])),
], ['no_wait_shutdown' => true]);

// First request: the helper receives request_init. Its RSHUTDOWN is skipped.
var_dump(rinit());

// The next request starts.
var_dump(req_lifecycle_rinit());
echo "connected after the next request's init: ";
var_dump(is_connected_to_helper());
echo "failed_count: ", backoff_status()['failed_count'], "\n";

// It reaches the helper on a new client.
var_dump(rinit());
echo "commands: ", implode(',', array_column($helper->get_commands(), 0)), "\n";
echo "connected: ";
var_dump(is_connected_to_helper());
?>
--EXPECTF--
bool(true)

Warning: datadog\appsec\testing\req_lifecycle_rinit(): [ddappsec] AppSec's shutdown did not run for the previous request; discarding its state in %s on line %d
bool(true)
connected after the next request's init: bool(false)
failed_count: 0
bool(true)
commands: client_init,request_init,client_init,request_init
connected: bool(true)
