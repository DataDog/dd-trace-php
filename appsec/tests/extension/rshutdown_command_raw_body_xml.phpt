--TEST--
request_shutdown — server.response.body.raw sent alongside parsed server.response.body for XML
--INI--
expose_php=0
datadog.appsec.enabled=1
datadog.appsec.raw_response_body_enabled=1
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

var_dump(rinit());
header('content-type: application/xml');
http_response_code(200);
$xml = <<<XML
<?xml version="1.0" standalone="yes"?>
<foo attr="bar">baz</foo>
XML;
echo "$xml\n";
$helper->get_commands(); // ignore

var_dump(rshutdown());
$c = $helper->get_commands();
$data = $c[0][1][0];

// both structured and raw body are present
var_dump(isset($data['server.response.body']));
var_dump(isset($data['server.response.body.raw']));
print_r($data['server.response.body']);
echo $data['server.response.body.raw'];
?>
--EXPECT--
bool(true)
<?xml version="1.0" standalone="yes"?>
<foo attr="bar">baz</foo>
bool(true)
bool(true)
bool(true)
Array
(
    [foo] => Array
        (
            [0] => Array
                (
                    [@attr] => bar
                )

            [1] => baz
        )

)
<?xml version="1.0" standalone="yes"?>
<foo attr="bar">baz</foo>
