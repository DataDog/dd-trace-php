--TEST--
Recover after an unavailable helper and a skipped request shutdown
--DESCRIPTION--
An unavailable helper skips AppSec for one request. A subsequent request_init
retries immediately; skipped shutdown must not prevent that request finishing.
--INI--
datadog.appsec.enabled=1
display_errors=0
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,req_lifecycle_rinit};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createRun([
    response_list(response('unavailable', [])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]]])),
], ['continuous' => true]);

rinit();
rshutdown();
echo "unavailable request: ", implode(',', array_column($helper->get_commands(), 0)), "\n";

// Simulate a skipped shutdown, then the following PHP request's RINIT.
rinit();
req_lifecycle_rinit();
rinit();
rshutdown();
echo "recovered request: ", implode(',', array_column($helper->get_commands(), 0)), "\n";
$helper->finished_with_commands();
?>
--EXPECT--
unavailable request: request_init
recovered request: request_init,request_init,request_shutdown
