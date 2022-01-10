--TEST--
request_init data on XML data
--POST_RAW--
<foo/>
--ENV--
CONTENT_TYPE=text/xml
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([['ok']]);

var_dump(rinit());

$c = $helper->get_commands();

var_dump($c[1][1][0]['server.request.body.raw']);

?>
--EXPECT--
bool(true)
string(6) "<foo/>"
