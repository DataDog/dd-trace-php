--TEST--
JSON and XML testing functions decode their MessagePack results internally
--INI--
extension=ddtrace.so
--FILE--
<?php

$json = datadog\appsec\convert_json('{"n":1,"0":"zero","n":2}');
var_dump($json);

$xml = datadog\appsec\convert_xml('<r/>', 'application/xml');
var_dump($xml);

foreach (['null', 'true', '123', '1.25', '"text"'] as $input) {
    var_dump(datadog\appsec\convert_json($input));
}
var_dump(datadog\appsec\convert_json(''));

// Neither the raw MessagePack buffer nor the decoded result survives a call.
$before = memory_get_usage();
for ($i = 0; $i < 100; ++$i) {
    datadog\appsec\convert_json('{"n":1}');
    datadog\appsec\convert_xml('<r/>', 'application/xml');
}
var_dump(memory_get_usage() === $before);
--EXPECT--
array(2) {
  ["n"]=>
  int(2)
  [0]=>
  string(4) "zero"
}
array(1) {
  ["r"]=>
  array(0) {
  }
}
NULL
bool(true)
int(123)
float(1.25)
string(4) "text"
NULL
bool(true)
