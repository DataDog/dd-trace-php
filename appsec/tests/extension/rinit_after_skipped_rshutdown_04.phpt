--TEST--
A connection kept after a skipped request shutdown still resets the connection backoff
--DESCRIPTION--
After an earlier connection failure, a request reconnects and the helper
answers its request_init without starting a helper request (config_features).
That request's RSHUTDOWN is skipped. The connection is kept, so it must count
as a success, as it would have in the skipped shutdown, and reset the shared
failure counter. Otherwise reconnects would stay serialized across processes
and the next failure would wait longer.
The test runs both requests in one process, so a root span forgotten by the
second one would stay alive and be reported as a leak. In a real request the
old span went away with the old request heap, so no root span is generated
here.
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
$helper = Helper::createRun([
    response_list(response_client_init(
        ['not-ok', phpversion('ddtrace'), ['some error'], $empty_obj, $empty_obj, null])),
    response_list(response_client_init(
        ['ok', phpversion('ddtrace'), [], $empty_obj, $empty_obj, null])),
    response_list(response_config_features(true)),
], ['no_wait_shutdown' => true]);

// A failed connection attempt.
rinit();
echo "failed_count after the failure: ", backoff_status()['failed_count'], "\n";

// Wait for the first backoff period (3 seconds) to end.
usleep(3200000);

// A request reconnects; its RSHUTDOWN is skipped.
rinit();
echo "failed_count after reconnecting: ", backoff_status()['failed_count'], "\n";

// The next request starts.
req_lifecycle_rinit();
echo "commands: ", implode(',', array_column($helper->get_commands(), 0)), "\n";
echo "connected: ";
var_dump(is_connected_to_helper());
echo "failed_count: ", backoff_status()['failed_count'], "\n";
?>
--EXPECT--
failed_count after the failure: 1
failed_count after reconnecting: 1
commands: client_init,client_init,request_init
connected: bool(true)
failed_count: 0
