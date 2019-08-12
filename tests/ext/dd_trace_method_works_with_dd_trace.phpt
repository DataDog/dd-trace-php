--TEST--
dd_trace_method() works alongside dd_trace()
--FILE--
<?php
use DDTrace\SpanData;

class Foo
{
    public function oldWay($favNumber, array $colors = [])
    {
        echo "Foo::oldWay($favNumber, [...])\n";
        return sprintf(
            "Old fav num is %d with %d colors and %s on top\n",
            $favNumber,
            count($colors),
            $colors[0]
        );
    }

    public function newWay($favNumber, array $colors = [])
    {
        echo "Foo::newWay($favNumber, [...])\n";
        return sprintf(
            "New fav num is %d with %d colors and %s on top\n",
            $favNumber,
            count($colors),
            $colors[0]
        );
    }
}

var_dump(dd_trace('Foo', 'oldWay', function () {
    echo "TRACED Test::oldWay()\n";
    var_dump(func_get_args());
    $retval = dd_trace_forward_call();
    var_dump($retval);
    return $retval;
}));
var_dump(dd_trace_method(
    'Foo', 'newWay',
    function (SpanData $span, $args, $retval) {
        echo "TRACED Test::newWay()\n";
        $span->name = 'FooName';
        $span->resource = 'FooResource';
        $span->service = 'FooService';
        $span->type = 'FooType';
        $span->meta = [
            'args.0' => isset($args[0]) ? $args[0] : '',
            'args.1.0' => isset($args[1][0]) ? $args[1][0] : '',
            'retval' => $retval,
        ];
    }
));

$foo = new Foo();
$old = $foo->oldWay(70, ['green', 'red', 'blue']);
var_dump($old);
$new = $foo->newWay(42, ['pink', 'blue', 'grey']);
var_dump($new);

echo "---\n";

var_dump(dd_trace_serialize_closed_spans());
var_dump(dd_trace_serialize_closed_spans());
?>
--EXPECTF--
bool(true)
bool(true)
TRACED Test::oldWay()
array(2) {
  [0]=>
  int(70)
  [1]=>
  array(3) {
    [0]=>
    string(5) "green"
    [1]=>
    string(3) "red"
    [2]=>
    string(4) "blue"
  }
}
Foo::oldWay(70, [...])
string(49) "Old fav num is 70 with 3 colors and green on top
"
string(49) "Old fav num is 70 with 3 colors and green on top
"
Foo::newWay(42, [...])
TRACED Test::newWay()
string(48) "New fav num is 42 with 3 colors and pink on top
"
---
array(1) {
  [0]=>
  array(9) {
    ["trace_id"]=>
    int(%d)
    ["span_id"]=>
    int(%d)
    ["start"]=>
    int(%d)
    ["duration"]=>
    int(%d)
    ["name"]=>
    string(7) "FooName"
    ["resource"]=>
    string(11) "FooResource"
    ["service"]=>
    string(10) "FooService"
    ["type"]=>
    string(7) "FooType"
    ["meta"]=>
    array(3) {
      ["args.0"]=>
      int(42)
      ["args.1.0"]=>
      string(4) "pink"
      ["retval"]=>
      string(48) "New fav num is 42 with 3 colors and pink on top
"
    }
  }
}
array(0) {
}
