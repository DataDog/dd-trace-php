--TEST--
request_shutdown — body size exceeded (enough for good parsing)
--INI--
expose_php=0
datadog.appsec.max_body_buff_size=16
datadog.appsec.enabled=1
--GET--
a=b
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

header('content-type: application/json');
http_response_code(403);
var_dump(rinit());
echo '{"a": [1,2,"3"]}FOOBAR', "\n";
$helper->get_commands(); // ignore

var_dump(rshutdown());
$c = $helper->get_commands();
print_r($c[0][1][0]['server.response.body']);

?>
--EXPECT--
bool(true)
{"a": [1,2,"3"]}FOOBAR
bool(true)
Array
(
    [a] => Array
        (
            [0] => 1
            [1] => 2
            [2] => 3
        )

)
