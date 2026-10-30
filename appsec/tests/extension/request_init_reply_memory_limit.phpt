--TEST--
Memory exhaustion processing request_init still sends one request_shutdown
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.cli_start_on_rinit=false
memory_limit=64M
display_errors=0
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use function DDTrace\UserRequest\{notify_start, notify_commit};
use function DDTrace\start_span;

include __DIR__ . '/inc/mock_helper.php';
$helper = null;
$returned = false;
register_shutdown_function(function () use (&$helper, &$returned) {
    ini_set('memory_limit', '64M');
    $error = error_get_last();
    var_dump(!$returned && $error['type'] === E_ERROR &&
        strpos($error['message'], 'Allowed memory size') !== false);
    datadog\appsec\testing\rshutdown();
    var_dump(datadog\appsec\push_addresses(['server.io.fs.file' => '/etc/passwd'], 'lfi'));
    $next = \DDTrace\start_trace_span();
    notify_start($next, ['_SERVER' => ['REQUEST_METHOD' => 'GET']]);
    notify_commit($next, 200, [], '');
    var_dump(array_column($helper->get_commands(), 0));
});
$helper = Helper::createInitedRun([
    response_list(response_request_init([
        [['record', []]], array_fill(0, 16000, '{}'), true
    ])),
    response_list(response_request_shutdown([[['ok', []]]])),
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]]]))
]);
$span = start_span();
gc_mem_caches();
$limit = memory_get_usage(true);
$ballast = [];
while (memory_get_usage(false) < $limit - 512 * 1024) {
    $ballast[] = str_repeat('x', 1024);
}
var_dump(ini_set('memory_limit', (string)$limit) !== false);
// PHP without deferred OOM bails out inside reply processing; patched PHP
// returns from notify_start() before raising the deferred error.
notify_start($span, ['_SERVER' => ['REQUEST_METHOD' => 'GET']]);
$returned = true;
?>
--EXPECT--
bool(true)
bool(true)
bool(false)
array(5) {
  [0]=>
  string(11) "client_init"
  [1]=>
  string(12) "request_init"
  [2]=>
  string(16) "request_shutdown"
  [3]=>
  string(12) "request_init"
  [4]=>
  string(16) "request_shutdown"
}
