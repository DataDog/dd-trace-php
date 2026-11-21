--TEST--
Recover the next AppSec request after a skipped request shutdown
--DESCRIPTION--
A typed request_init abandons an unfinished helper request. The next PHP
request's RINIT also forgets extension state without contacting the helper.
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
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]]])),
], ['continuous' => true]);

rinit();
rinit();
echo "direct init: ", implode(',', array_column($helper->get_commands(), 0)), "\n";

// No root spans: these simulated requests share a heap, whereas a real
// request would have freed its old span when its heap was torn down.
req_lifecycle_rinit();

rinit();
rshutdown();
echo "next request: ", implode(',', array_column($helper->get_commands(), 0)), "\n";
$helper->finished_with_commands();
?>
--EXPECT--
direct init: request_init,request_init
next request: request_init,request_shutdown
