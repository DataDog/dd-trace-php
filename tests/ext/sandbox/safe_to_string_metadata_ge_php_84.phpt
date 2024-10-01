--TEST--
Span metadata is safely converted to strings without errors or exceptions
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80400) {
    die('skip: test only stable on PHP >= 8.4');
}
?>
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

DDTrace\trace_function('meta_to_string', function (SpanData $span, array $args) {
    $span->name = 'MetaToString';
    $span->meta = [];
    foreach ($args as $key => $arg) {
        $span->meta['arg.' . $key] = $arg;
    }
});

$allTheTypes = [
    ['recursive'],
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
    ['bar' => [1, "key" => 2]],
    fopen('php://memory', 'rb'), // resource
    MY_STRING,
    MY_INT,
    MY_BOOL,
    MyDt::CLASS_CONST_FLOAT,
];
$allTheTypes[0][1] = &$allTheTypes[0];

call_user_func_array('meta_to_string', $allTheTypes);

list($span) = dd_trace_serialize_closed_spans();
unset($span['meta']['process_id']);
$last = -1;
foreach ($span['meta'] as $key => $value) {
    $index = (int)substr($key, 4);
    if ($last != $index) {
        echo PHP_EOL;
        if ($index == 0) {
            unset($allTheTypes[$index][1]); // *RECURSION* is inconsistent across PHP versions
        }
        var_dump($allTheTypes[$index]);
        $last = $index;
    }
    echo "$key: ";
    var_dump($value);
}
?>
--EXPECTF--

array(1) {
  [0]=>
  string(9) "recursive"
}
arg.0.0: string(9) "recursive"
arg.0.1: string(0) ""

string(16) "already a string"
arg.1: string(16) "already a string"

int(42)
arg.2: string(2) "42"

float(4.2)
arg.3: string(3) "4.2"

bool(true)
arg.4: string(4) "true"

bool(false)
arg.5: string(5) "false"

NULL
arg.6: string(4) "null"

object(Closure)#%d (3) {
  ["name"]=>
  string(%d) "{closure%s}"
  ["file"]=>
  string(%d) "%s"
  ["line"]=>
  int(%d)
}
arg.7: string(0) ""

object(DateTime)#%d (3) {
  ["date"]=>
  string(26) "2019-09-10 00:00:00.000000"
  ["timezone_type"]=>
  int(3)
  ["timezone"]=>
  string(3) "UTC"
}
arg.8.date: string(26) "2019-09-10 00:00:00.000000"
arg.8.timezone_type: string(1) "3"
arg.8.timezone: string(3) "UTC"

object(MyDt)#%d (3) {
  ["date"]=>
  string(26) "2019-09-10 00:00:00.000000"
  ["timezone_type"]=>
  int(3)
  ["timezone"]=>
  string(3) "UTC"
}
arg.9.date: string(26) "2019-09-10 00:00:00.000000"
arg.9.timezone_type: string(1) "3"
arg.9.timezone: string(3) "UTC"

array(1) {
  ["foo"]=>
  int(0)
}
arg.10.foo: string(1) "0"

array(1) {
  ["bar"]=>
  array(2) {
    [0]=>
    int(1)
    ["key"]=>
    int(2)
  }
}
arg.11.bar.0: string(1) "1"
arg.11.bar.key: string(1) "2"

resource(%d) of type (stream)
arg.12: string(%d) "Resource id #%d"

string(17) "string from const"
arg.13: string(17) "string from const"

int(42)
arg.14: string(2) "42"

bool(true)
arg.15: string(4) "true"

float(4.2)
arg.16: string(3) "4.2"
