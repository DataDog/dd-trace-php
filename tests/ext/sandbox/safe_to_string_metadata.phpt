--TEST--
Span metadata is safely converted to strings without errors or exceptions
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--FILE--
<?php
use DDTrace\SpanData;

const MY_STRING = 'string from const';
const MY_INT = 42;
const MY_BOOL = true;

date_default_timezone_set('UTC');

class MyDt extends DateTime {
    const CLASS_CONST_FLOAT = 4.2;
    public function __toString() {
        return $this->format('Y-m-d');
    }
}

function meta_to_string() {}

dd_trace_function('meta_to_string', function (SpanData $span, array $args) {
    $span->name = 'MetaToString';
    $span->meta = [];
    foreach ($args as $key => $arg) {
        $span->meta['arg.' . $key] = $arg;
    }
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
    MY_STRING,
    MY_INT,
    MY_BOOL,
    MyDt::CLASS_CONST_FLOAT,
];
// At the time of writing PHP 5 cannot trace call_user_func*() :/
//call_user_func_array('meta_to_string', $allTheTypes);
meta_to_string(
    $allTheTypes[0],
    $allTheTypes[1],
    $allTheTypes[2],
    $allTheTypes[3],
    $allTheTypes[4],
    $allTheTypes[5],
    $allTheTypes[6],
    $allTheTypes[7],
    $allTheTypes[8],
    $allTheTypes[9],
    $allTheTypes[10],
    $allTheTypes[11],
    $allTheTypes[12],
    $allTheTypes[13],
    $allTheTypes[14]
);

list($span) = dd_trace_serialize_closed_spans();
unset($span['meta']['system.pid']);
$i = 0;
foreach ($span['meta'] as $key => $value) {
    var_dump($allTheTypes[$i++]);
    var_dump($value);
    echo PHP_EOL;
}
?>
--EXPECTF--
string(16) "already a string"
string(16) "already a string"

int(42)
string(2) "42"

float(4.2)
string(3) "4.2"

bool(true)
string(6) "(true)"

bool(false)
string(7) "(false)"

NULL
string(6) "(null)"

object(Closure)#%d (0) {
}
string(%d) "object(Closure)#%d"

object(DateTime)#%d (3) {
  ["date"]=>
  string(26) "2019-09-10 00:00:00.000000"
  ["timezone_type"]=>
  int(3)
  ["timezone"]=>
  string(3) "UTC"
}
string(%d) "object(DateTime)#%d"

object(MyDt)#%d (3) {
  ["date"]=>
  string(26) "2019-09-10 00:00:00.000000"
  ["timezone_type"]=>
  int(3)
  ["timezone"]=>
  string(3) "UTC"
}
string(10) "2019-09-10"

array(1) {
  ["foo"]=>
  int(0)
}
string(5) "Array"

resource(%d) of type (curl)
string(%d) "Resource id #%d"

string(17) "string from const"
string(17) "string from const"

int(42)
string(2) "42"

bool(true)
string(6) "(true)"

float(4.2)
string(3) "4.2"
