--TEST--
Memory exhaustion processing client_init leaves no reusable connection after shutdown
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
use function DDTrace\UserRequest\notify_start;
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
    var_dump(array_column($helper->get_commands(), 0));
    var_dump(!datadog\appsec\testing\is_connected_to_helper());
});
$tags = [];
for ($i = 0; $i < 9000; $i++) {
    $tags['k' . $i] = 'v';
}
$helper = Helper::createRun([
    response_list(response_client_init([
        'ok', phpversion('ddappsec'), [], $tags, new ArrayObject(), null
    ]))
], ['no_wait_shutdown' => true]);
unset($tags);
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
array(1) {
  [0]=>
  string(11) "client_init"
}
bool(true)
