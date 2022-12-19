--TEST--
request_shutdown sends headers and response code
--INI--
expose_php=0
--GET--
a=b
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init(['ok', []])),
    response_list(response_request_shutdown(['ok', [], new ArrayObject(), new ArrayObject()]))
]);

header('Foo:bar');
header('My_header: value 1');
header('my-header:value 2');
header('content-type: text/plain;charset=ISO-8859-1');
http_response_code(403);
var_dump(rinit());
$helper->get_commands(); // ignore

var_dump(rshutdown());
$c = $helper->get_commands();
print_r($c[0]);

?>
--EXPECT--
bool(true)
bool(true)
Array
(
    [0] => request_shutdown
    [1] => Array
        (
            [0] => Array
                (
                    [server.response.status] => 403
                    [server.response.headers.no_cookies] => Array
                        (
                            [foo] => Array
                                (
                                    [0] => bar
                                )

                            [my-header] => Array
                                (
                                    [0] => value 1
                                    [1] => value 2
                                )

                            [content-type] => Array
                                (
                                    [0] => text/plain;charset=ISO-8859-1
                                )

                        )

                )

        )

)
