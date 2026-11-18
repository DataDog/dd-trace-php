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

rinit();
$helper->get_commands(); // ignore

header('content-type: application/xml');
http_response_code(403);
$xml = <<<XML
<?xml version="1.0" standalone="yes"?>
<foo attr="bar">
test<br/>baz
</foo>
XML;
echo "$xml\n";

var_dump(rshutdown());
$c = $helper->get_commands();
print_r($c[0][1][0]['server.response.body']);

?>
--EXPECT--
<?xml version="1.0" standalone="yes"?>
<foo attr="bar">
test<br/>baz
</foo>
bool(true)
Array
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
