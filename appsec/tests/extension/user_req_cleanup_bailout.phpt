--TEST--
A destructor bailout during user request cleanup leaves no stale globals
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.cli_start_on_rinit=false
display_errors=0
error_reporting=0
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use function DDTrace\UserRequest\{notify_start, notify_commit};
use function DDTrace\{start_span, close_span};

include __DIR__ . '/inc/mock_helper.php';
$helper = null;
$returned = false;
register_shutdown_function(function () use (&$helper, &$returned) {
    var_dump(!$returned && error_get_last()['message'] === 'Cleanup failed');
    datadog\appsec\testing\rshutdown();
    var_dump(array_column($helper->get_commands(), 0));
});
class CleanupFailure {
    function __destruct() {
        trigger_error('Cleanup failed', E_USER_ERROR);
    }
}
$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]]]))
]);
$span = start_span();
notify_start($span, ['_SERVER' => ['REQUEST_METHOD' => 'GET'],
    'cleanup' => new CleanupFailure()]);
notify_commit($span, 200, [], '');
close_span();
$returned = true;
?>
--EXPECT--
bool(true)
array(3) {
  [0]=>
  string(11) "client_init"
  [1]=>
  string(12) "request_init"
  [2]=>
  string(16) "request_shutdown"
}
