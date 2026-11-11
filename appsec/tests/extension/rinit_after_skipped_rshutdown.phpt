--TEST--
Request init after a skipped request shutdown does not send request_init while the helper is still inside the previous request
--DESCRIPTION--
When AppSec's RSHUTDOWN does not run (a bailout in the RINIT of a module
loaded after ddtrace, a bailout in an earlier RSHUTDOWN on PHP < 8.1), the
helper is left inside the request that received request_init. The helper
only accepts request_exec, request_shutdown or client_shutdown there, and
rejects anything else as an unexpected command. Here the first request ends
without rshutdown(), and the second one must not start with request_init
or config_sync on the same helper client.
--INI--
datadog.appsec.enabled=1
display_errors=0
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

// The extension's third command, whichever it is, gets an error response.
// Only which command it was matters here.
$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response("error", [])),
], ['no_wait_shutdown' => true]);

// First request: the helper receives request_init. Its RSHUTDOWN is skipped.
var_dump(rinit());

// Second request on the same process and connection.
var_dump(rinit());

$commands = array_column($helper->get_commands(), 0);
echo "first two commands: ", implode(',', array_slice($commands, 0, 2)), "\n";
echo "third command is valid inside a request: ";
var_dump(isset($commands[2]) &&
    !in_array($commands[2], ['request_init', 'config_sync'], true));
?>
--EXPECT--
bool(true)
bool(true)
first two commands: client_init,request_init
third command is valid inside a request: bool(true)
