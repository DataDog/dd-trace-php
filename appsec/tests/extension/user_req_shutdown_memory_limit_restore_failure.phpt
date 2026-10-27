--TEST--
User request shutdown enforces the memory limit after retaining reply tags
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
        strpos($error['message'], 'Failed to restore memory limit after AppSec request shutdown') !== false);
    var_dump($GLOBALS['span']->meta['k8999']);
    var_dump(array_column($helper->get_commands(), 0));
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
$returned = true;
?>
--EXPECT--
bool(true)
bool(true)
string(1) "v"
array(3) {
  [0]=>
  string(11) "client_init"
  [1]=>
  string(12) "request_init"
  [2]=>
  string(16) "request_shutdown"
}
