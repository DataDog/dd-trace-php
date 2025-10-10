--TEST--
If no block_id given by helper, then no replacement happen
--INI--
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit, rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['redirect', ['status_code' => 'bad', 'location' => 'http://alex.com?block_id={block_id}']]], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();

?>
Some content here which should not be displayed
--EXPECTHEADERS--
Status: 303 See Other
Content-type: text/html; charset=UTF-8
--EXPECTF--
Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and attempted a redirection to http://alex.com?block_id={block_id} - block_id:  in %s on line %s

