--TEST--
request_shutdown — body size exceeded
--INI--
expose_php=0
datadog.appsec.max_body_buff_size=15
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

$res_rinit = rinit();

header('content-type: application/json');
http_response_code(403);
echo '{"a": [1,2,"3","4"]}', "\n";
$helper->get_commands(); // ignore

var_dump($res_rinit);
var_dump(rshutdown());
$c = $helper->get_commands();
print_r($c[0][1][0]);

?>
--EXPECT--
{"a": [1,2,"3","4"]}
bool(true)
bool(true)
Array
(
    [server.response.status] => 403
    [server.response.headers.no_cookies] => Array
        (
            [content-type] => Array
                (
                    [0] => application/json
                )

        )

    [server.response.body] => Array
        (
            [a] => Array
                (
                    [0] => 1
                    [1] => 2
                    [2] => 3
                )

        )

)
