--TEST--
Request parser memory-limit exhaustion is fatal with native femalloc or its fallback
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.cli_start_on_rinit=false
datadog.appsec.max_body_buff_size=4194304
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
$parseLine = null;
$message = datadog\appsec\testing\body_parser_has_femalloc()
    ? 'Failed to allocate memory while parsing the AppSec request body'
    : 'AppSec body parser exceeded the memory limit';
register_shutdown_function(function () use (&$helper, &$returned, &$parseLine, $message) {
    ini_set('memory_limit', '64M');
    $error = error_get_last();
    var_dump(!$returned && $error['type'] === E_ERROR && $error['line'] === $parseLine &&
        strpos($error['message'], $message) !== false);
    var_dump(datadog\appsec\convert_json('[1]'));
    var_dump(datadog\appsec\push_addresses(['server.io.fs.file' => '/etc/passwd'], 'lfi'));
    datadog\appsec\testing\rshutdown();
    var_dump(array_column($helper->get_commands(), 0));
});
$helper = Helper::createInitedRun([]);
$span = start_span();
$body = '<!DOCTYPE r [<!ENTITY unused "' . str_repeat('x', 2000000) . '">]><r/>';
gc_mem_caches();
ini_set('memory_limit', (string)(memory_get_usage(true) + 2 * 1024 * 1024));
$parseLine = __LINE__ + 1;
notify_start($span, ['_SERVER' => ['CONTENT_TYPE' => 'text/xml']], $body);
$returned = true;
?>
--EXPECT--
bool(true)
array(1) {
  [0]=>
  int(1)
}
bool(false)
array(1) {
  [0]=>
  string(11) "client_init"
}
