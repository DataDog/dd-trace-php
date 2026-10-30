--TEST--
PHP shutdown parser preflight uses the raised limit, including without femalloc
--INI--
extension=ddtrace.so
expose_php=0
datadog.appsec.enabled=1
--GET--
a=b
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown};

include __DIR__ . '/inc/mock_helper.php';
$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]]]))
]);
header('content-type: text/xml');
rinit();
echo '<r>text</r>';
ini_set('memory_limit', (string)memory_get_usage(true));
rshutdown();
ini_set('memory_limit', '64M');
$commands = $helper->get_commands();
var_dump($commands[2][1][0]['server.response.body']);
?>
--EXPECT--
<r>text</r>array(1) {
  ["r"]=>
  array(1) {
    [0]=>
    string(4) "text"
  }
}
