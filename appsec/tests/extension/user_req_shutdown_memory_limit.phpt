--TEST--
User request shutdown may exceed the limit temporarily and restores it afterwards
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
use function DDTrace\UserRequest\{notify_start, notify_commit};
use function DDTrace\start_span;

include __DIR__ . '/inc/mock_helper.php';
$helper = null;
$completed = false;
$outsideLine = null;
register_shutdown_function(function () use (&$helper, &$completed, &$outsideLine) {
    ini_set('memory_limit', '64M');
    $error = error_get_last();
    var_dump($completed && $error['type'] === E_ERROR && $error['line'] === $outsideLine &&
        strpos($error['message'], 'Allowed memory size') !== false);
    $commands = $helper->get_commands();
    var_dump(array_column($commands, 0));
    var_dump($commands[2][1][0]['server.response.body']);
});
$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([
        [['ok', []]], [], false, [], ['guard.reply' => 'received'], ['guard.reply' => 1]
    ]))
]);
$span = start_span();
notify_start($span, ['_SERVER' => ['REQUEST_METHOD' => 'GET']]);
// The unused entity needs parser scratch space without filling the mock's echo pipe.
$body = '<!DOCTYPE r [<!ENTITY unused "' . str_repeat('x', 2000000) . '">]><r>text</r>';
$headers = ['Content-Type' => ['text/xml']];
gc_mem_caches();
$limit = memory_get_usage(true);
ini_set('memory_limit', (string)$limit);
notify_commit($span, 200, $headers, $body);
$completed = true;
var_dump($span->meta['guard.reply'], $span->metrics['guard.reply']);
$outsideLine = __LINE__ + 1;
$outside = str_repeat('x', $limit + 2 * 1024 * 1024);
echo "Limit was not restored\n";
?>
--EXPECT--
string(8) "received"
int(1)
bool(true)
array(3) {
  [0]=>
  string(11) "client_init"
  [1]=>
  string(12) "request_init"
  [2]=>
  string(16) "request_shutdown"
}
array(1) {
  ["r"]=>
  array(1) {
    [0]=>
    string(4) "text"
  }
}
