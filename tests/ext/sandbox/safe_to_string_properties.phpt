--TEST--
Span properties are safely converted to strings without errors or exceptions
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
use DDTrace\SpanData;

date_default_timezone_set('UTC');

class MyDt extends DateTime {
    public function __toString() {
        return $this->format('Y-m-d');
    }
}

function prop_to_string($data) {}

dd_trace_function('prop_to_string', function (SpanData $span, array $args) {
    $span->name = $args[0];
    $span->resource = $args[0];
    $span->service = $args[0];
    $span->type = $args[0];
});

$allTheTypes = [
    'already a string',
    42,
    4.2,
    true,
    false,
    null,
    function () {},
    new DateTime('2019-09-10'),
    new MyDt('2019-09-10'),
    ['foo' => 0],
    curl_init(), // resource
];
foreach ($allTheTypes as $value) {
    prop_to_string($value);
}

// Spans are on a stack so we reverse the types
$allTheTypes = array_reverse($allTheTypes);

$i = 0;
array_map(function($span) use (&$i, $allTheTypes) {
    var_dump($allTheTypes[$i]);
    foreach (['name', 'resource', 'service', 'type'] as $prop) {
        if (isset($span[$prop])) {
            var_dump($span[$prop]);
        } else {
            printf("'%s' dropped\n", $prop);
        }
    }
    echo PHP_EOL;
    $i++;
}, dd_trace_serialize_closed_spans());
?>
--EXPECTF--
resource(%d) of type (curl)
string(%d) "Resource id #%d"
string(%d) "Resource id #%d"
string(%d) "Resource id #%d"
string(%d) "Resource id #%d"

array(1) {
  ["foo"]=>
  int(0)
}
string(5) "Array"
string(5) "Array"
string(5) "Array"
string(5) "Array"

object(MyDt)#%d (3) {
  ["date"]=>
  string(26) "2019-09-10 00:00:00.000000"
  ["timezone_type"]=>
  int(3)
  ["timezone"]=>
  string(3) "UTC"
}
string(10) "2019-09-10"
string(10) "2019-09-10"
string(10) "2019-09-10"
string(10) "2019-09-10"

object(DateTime)#%d (3) {
  ["date"]=>
  string(26) "2019-09-10 00:00:00.000000"
  ["timezone_type"]=>
  int(3)
  ["timezone"]=>
  string(3) "UTC"
}
string(%d) "object(DateTime)#%d"
string(%d) "object(DateTime)#%d"
string(%d) "object(DateTime)#%d"
string(%d) "object(DateTime)#%d"

object(Closure)#%d (0) {
}
string(%d) "object(Closure)#%d"
string(%d) "object(Closure)#%d"
string(%d) "object(Closure)#%d"
string(%d) "object(Closure)#%d"

NULL
'name' dropped
'resource' dropped
'service' dropped
'type' dropped

bool(false)
string(7) "(false)"
string(7) "(false)"
string(7) "(false)"
string(7) "(false)"

bool(true)
string(6) "(true)"
string(6) "(true)"
string(6) "(true)"
string(6) "(true)"

float(4.2)
string(3) "4.2"
string(3) "4.2"
string(3) "4.2"
string(3) "4.2"

int(42)
string(2) "42"
string(2) "42"
string(2) "42"
string(2) "42"

string(16) "already a string"
string(16) "already a string"
string(16) "already a string"
string(16) "already a string"
string(16) "already a string"
