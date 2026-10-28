--TEST--
Request parser OOM is fatal after cleanup and sends no request_init or shutdown
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
datadog.appsec.cli_start_on_rinit=false
display_errors=0
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use function DDTrace\UserRequest\notify_start;
use function DDTrace\start_span;
use function datadog\appsec\testing\body_parser_fail_after;

include __DIR__ . '/inc/mock_helper.php';
$helper = null;
$returned = false;
$parseLine = null;
register_shutdown_function(function () use (&$helper, &$returned, &$parseLine) {
    $error = error_get_last();
    var_dump(!$returned && $error['type'] === E_ERROR && $error['line'] === $parseLine &&
        strpos($error['message'], 'Failed to allocate memory while parsing the AppSec request body') !== false);
    body_parser_fail_after();
    var_dump(datadog\appsec\convert_json('[1]'));
    var_dump(datadog\appsec\push_addresses(['server.io.fs.file' => '/etc/passwd'], 'lfi'));
    datadog\appsec\testing\rshutdown();
    var_dump(array_column($helper->get_commands(), 0));
});
$helper = Helper::createInitedRun([]);
$span = start_span();
body_parser_fail_after(0);
$parseLine = __LINE__ + 1;
notify_start($span, ['_SERVER' => ['CONTENT_TYPE' => 'application/json']], '{"a":1}');
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
