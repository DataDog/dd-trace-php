--TEST--
Span metrics is safely converted to numerics without errors or exceptions
--FILE--
<?php
use DDTrace\SpanData;

const MY_DOUBLE = 4.2;
const MY_LONG = 42;

function metrics_to_string() {}

DDTrace\trace_function('metrics_to_string', function (SpanData $span, array $args) {
    $span->name = 'MetricsToString';
    $span->meta = [];
    foreach ($args as $key => $arg) {
        $span->metrics['arg.' . $key] = $arg;
    }
});

$allTheTypes = [
    [42],
    42,
    4.2,
    MY_DOUBLE,
    MY_LONG,
    [4.2],
    [1.1, 2.2, 3.3],
    [[MY_DOUBLE, MY_LONG], 2.2, [3.3]]
];
$allTheTypes[0][1] = &$allTheTypes[0];

call_user_func_array('metrics_to_string', $allTheTypes);

list($span) = dd_trace_serialize_closed_spans();
$last = -1;
foreach ($span['metrics'] as $key => $value) {
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
  int(42)
}
arg.0.0: float(42)
arg.0.1: float(0)

int(42)
arg.1: float(42)

float(4.2)
arg.2: float(4.2)

float(4.2)
arg.3: float(4.2)

int(42)
arg.4: float(42)

array(1) {
  [0]=>
  float(4.2)
}
arg.5.0: float(4.2)

array(3) {
  [0]=>
  float(1.1)
  [1]=>
  float(2.2)
  [2]=>
  float(3.3)
}
arg.6.0: float(1.1)
arg.6.1: float(2.2)
arg.6.2: float(3.3)

array(3) {
  [0]=>
  array(2) {
    [0]=>
    float(4.2)
    [1]=>
    int(42)
  }
  [1]=>
  float(2.2)
  [2]=>
  array(1) {
    [0]=>
    float(3.3)
  }
}
arg.7.0.0: float(4.2)
arg.7.0.1: float(42)
arg.7.1: float(2.2)
arg.7.2.0: float(3.3)
