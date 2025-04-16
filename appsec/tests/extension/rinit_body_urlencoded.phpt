--TEST--
request_init data on x-www-form-urlencoded data
--INI--
datadog.appsec.testing_raw_body=1
datadog.appsec.enabled=1
--POST--
a[]=1&a[]=2&a[a]=3&a[-1]=4&a[2][]=5
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]]))
]);

var_dump($_POST);
var_dump(rinit());

$c = $helper->get_commands();

echo "server.request.body:\n";
var_dump($c[1][1][0]['server.request.body']);

echo "server.request.body.raw:\n";
var_dump($c[1][1][0]['server.request.body.raw']);

?>
--EXPECT--
array(1) {
  ["a"]=>
  array(5) {
    [0]=>
    string(1) "1"
    [1]=>
    string(1) "2"
    ["a"]=>
    string(1) "3"
    [-1]=>
    string(1) "4"
    [2]=>
    array(1) {
      [0]=>
      string(1) "5"
    }
  }
}
bool(true)
server.request.body:
array(1) {
  ["a"]=>
  array(5) {
    [0]=>
    string(1) "1"
    [1]=>
    string(1) "2"
    ["a"]=>
    string(1) "3"
    [-1]=>
    string(1) "4"
    [2]=>
    array(1) {
      [0]=>
      string(1) "5"
    }
  }
}
server.request.body.raw:
string(35) "a[]=1&a[]=2&a[a]=3&a[-1]=4&a[2][]=5"
