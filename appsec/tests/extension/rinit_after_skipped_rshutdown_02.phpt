--TEST--
Request init after a skipped request shutdown reconnects to the helper without tripping the connection backoff
--DESCRIPTION--
When the helper is still inside a request that the extension will never
finish, the extension drops the connection locally and the next client_init
replaces the helper's client. That drop is caused by the extension, not by
a helper failure, so it must not count toward the connection backoff, and
the new request must still reach the helper.
--INI--
datadog.appsec.enabled=1
display_errors=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,backoff_status,is_connected_to_helper};

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

// Second request on the same process.
var_dump(rinit());

echo "commands: ", implode(',', array_column($helper->get_commands(), 0)), "\n";
echo "connected: ";
var_dump(is_connected_to_helper());
echo "failed_count: ", backoff_status()['failed_count'], "\n";
?>
--EXPECT--
bool(true)
bool(true)
commands: client_init,request_init,client_init,request_init
connected: bool(true)
failed_count: 0
