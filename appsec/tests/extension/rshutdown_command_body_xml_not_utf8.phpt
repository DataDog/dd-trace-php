--TEST--
request_shutdown — xml body variant (not utf-8)
--INI--
expose_php=0
datadog.appsec.enabled=1
extension=ddtrace.so
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

header('content-type: application/xml;charset=iso-8859-1');
http_response_code(403);
$xml = <<<XML
<?xml version="1.0" standalone="yes"?>
<foo attr="bar">
test<br/>baz
</foo>
XML;
echo "$xml\n";
var_dump(rinit());
$helper->get_commands(); // ignore

var_dump(rshutdown());
$c = $helper->get_commands();
print_r($c[0]);

?>
--EXPECT--
<?xml version="1.0" standalone="yes"?>
<foo attr="bar">
test<br/>baz
</foo>
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
                            [content-type] => Array
                                (
                                    [0] => application/xml;charset=iso-8859-1
                                )

                        )

                )

        )

)
