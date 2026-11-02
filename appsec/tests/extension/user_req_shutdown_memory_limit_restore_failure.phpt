--TEST--
User request shutdown ends the worker under a finite memory limit after retaining reply tags
--INI--
datadog.appsec.enabled=1
datadog.appsec.cli_start_on_rinit=false
memory_limit=64M
display_errors=1
fatal_error_backtraces=0
error_reporting=E_ALL & ~E_WARNING
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use function DDTrace\UserRequest\{notify_start, notify_commit};
use function DDTrace\start_span;

include __DIR__ . '/inc/mock_helper.php';
register_shutdown_function(function () {
    // The restore failed: the worker ends, but usage + 8 MiB still bounds the
    // rest. The memory error is last: on a partial runtime (EOL minimal fix)
    // it ends the process.
    var_dump(error_get_last()['message']);
    var_dump($GLOBALS['span']->meta['k8999']);
    var_dump(array_column($GLOBALS['helper']->get_commands(), 0));
    str_repeat('x', 16 * 1024 * 1024);
    echo "unbounded\n";
});
$tags = [];
for ($i = 0; $i < 9000; $i++) {
    $tags['k' . $i] = 'v';
}
$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([
        [['ok', []]], [], false, [], $tags
    ]))
]);
unset($tags);
$span = start_span();
notify_start($span, ['_SERVER' => ['REQUEST_METHOD' => 'GET']]);
// Leave less space than the metadata retained on the span needs.
gc_mem_caches();
$limit = memory_get_usage(true);
$ballast = [];
while (memory_get_usage(false) < $limit - 512 * 1024) {
    $ballast[] = str_repeat('x', 1024);
}
var_dump(ini_set('memory_limit', (string)$limit) !== false);
notify_commit($span, 200, [], '');
echo "not reached\n";
?>
--EXPECTF--
bool(true)

Fatal error: Failed to restore memory limit after AppSec request shutdown in %s on line %d
string(60) "Failed to restore memory limit after AppSec request shutdown"
string(1) "v"
array(3) {
  [0]=>
  string(11) "client_init"
  [1]=>
  string(12) "request_init"
  [2]=>
  string(16) "request_shutdown"
}

Fatal error: Allowed memory size of %d bytes exhausted%s(tried to allocate %d bytes) in %s on line %d
