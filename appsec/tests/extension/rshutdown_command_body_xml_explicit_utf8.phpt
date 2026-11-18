--TEST--
request_shutdown — xml body variant
--INI--
expose_php=0
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

header('content-type: application/xml;charset=Utf-8');
http_response_code(403);
var_dump(rinit());
$xml = <<<XML
<?xml version="1.0" standalone="yes"?>
<foo attr="bar">
test<br/>baz
</foo>
XML;
echo "$xml\n";
$helper->get_commands(); // ignore

var_dump(rshutdown());
$c = $helper->get_commands();
print_r($c[0][1][0]);

?>
--EXPECT--
bool(true)
<?xml version="1.0" standalone="yes"?>
<foo attr="bar">
test<br/>baz
</foo>
bool(true)
Array
(
    [server.response.status] => 403
    [server.response.headers.no_cookies] => Array
        (
            [content-type] => Array
                (
                    [0] => application/xml;charset=Utf-8
                )

        )

    [server.response.body] => Array
        (
            [foo] => Array
                (
                    [0] => Array
                        (
                            [@attr] => bar
                        )

                    [1] => 
test
                    [2] => Array
                        (
                            [br] => Array
                                (
                                )

                        )

                    [3] => baz

                )

        )

)
