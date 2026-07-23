--TEST--
request_shutdown — server.response.body.raw not sent when feature is disabled (default)
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

var_dump(rinit());
header('content-type: text/plain');
http_response_code(200);
echo "plain text body\n";
$helper->get_commands(); // ignore

var_dump(rshutdown());
$c = $helper->get_commands();
var_dump(array_key_exists('server.response.body.raw', $c[0][1][0]));
?>
--EXPECT--
bool(true)
plain text body
bool(true)
bool(false)
