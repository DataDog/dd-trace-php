--TEST--
request_shutdown — server.response.body.raw sent for non-JSON/XML content types
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
header('content-type: text/plain');
http_response_code(200);
echo "plain text body\n";
$helper->get_commands(); // ignore

var_dump(rshutdown());
$c = $helper->get_commands();
$data = $c[0][1][0];

// server.response.body is absent for non-JSON/XML
var_dump(isset($data['server.response.body']));
// server.response.body.raw is present with the raw text
var_dump(isset($data['server.response.body.raw']));
echo $data['server.response.body.raw'];
?>
--EXPECT--
bool(true)
plain text body
bool(true)
bool(false)
bool(true)
plain text body
