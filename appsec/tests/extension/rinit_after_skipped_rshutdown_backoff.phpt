--TEST--
Reset the connection backoff when a skipped shutdown leaves an idle helper
--DESCRIPTION--
A connection attempt fails, then a retry succeeds. The helper replies with
config_features, which leaves it outside a request. AppSec can therefore keep
the connection when it discovers that request shutdown was skipped.

Keeping a working connection must reset the failure count, just as normal
request shutdown would. Otherwise later failures would incur longer backoffs.
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

// No root spans: these simulated requests share a heap, so forgetting an old
// span would leak it rather than leave it to the previous request's teardown.
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
