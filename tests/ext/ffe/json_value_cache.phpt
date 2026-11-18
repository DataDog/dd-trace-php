--TEST--
FFE JSON flag values are shared per configuration, copy-on-write, and survive a configuration change
--SKIPIF--
<?php if (PHP_VERSION_ID < 70300) die('skip: before PHP 7.3 ResultMapper decodes the JSON text'); ?>
--INI--
datadog.trace.generate_root_span=0
--FILE--
<?php
function config($value) {
    return json_encode(array(
        'createdAt' => '2024-01-01T00:00:00Z',
        'environment' => array('name' => 'test'),
        'flags' => array(
            'object.flag' => array(
                'key' => 'object.flag', 'enabled' => true, 'variationType' => 'JSON',
                'variations' => array('on' => array('key' => 'on', 'value' => $value)),
                'allocations' => array(array(
                    'key' => 'allocation', 'rules' => array(),
                    'splits' => array(array('variationKey' => 'on', 'shards' => array())),
                    'doLog' => false,
                )),
            ),
        ),
    ));
}

function evaluate() {
    $result = DDTrace\ffe_evaluate('object.flag', DDTrace\FFE_OBJECT, 'user', array(), false);
    return $result->value;
}

$first = array(
    'name' => 'first',
    '7' => 'numeric key',
    'nested' => array('list' => array(1, 2.5, true, null, 'text'), 'empty' => array()),
    'big' => 9223372036854775807,
);
var_dump(DDTrace\Testing\ffe_load_config(config($first)));

$a = evaluate();
$b = evaluate();
var_dump($a === $b);
echo json_encode($a), "\n";
var_dump(array_keys($a));

// Writes separate a local copy; the next evaluation is unchanged.
$a['name'] = 'changed';
$a['nested']['list'][] = 'appended';
foreach ($b['nested']['list'] as &$item) {
    $item = 'by-reference';
}
unset($item);
$c = evaluate();
var_dump($c['name'], count($c['nested']['list']), $c['nested']['list'][0]);

// A new configuration has new values; arrays from the old one stay valid.
var_dump(DDTrace\Testing\ffe_load_config(config(array('name' => 'second'))));
echo json_encode(evaluate()), "\n";
echo json_encode($c), "\n";
var_dump($c === evaluate());

// A short top-level list: the borrowed JSON text has no terminating NUL.
var_dump(DDTrace\Testing\ffe_load_config(config(array(1, 2))));
echo json_encode(evaluate()), "\n";
?>
--EXPECT--
bool(true)
bool(true)
{"name":"first","7":"numeric key","nested":{"list":[1,2.5,true,null,"text"],"empty":[]},"big":9223372036854775807}
array(4) {
  [0]=>
  string(4) "name"
  [1]=>
  int(7)
  [2]=>
  string(6) "nested"
  [3]=>
  string(3) "big"
}
string(5) "first"
int(5)
int(1)
bool(true)
{"name":"second"}
{"name":"first","7":"numeric key","nested":{"list":[1,2.5,true,null,"text"],"empty":[]},"big":9223372036854775807}
bool(false)
bool(true)
[1,2]
