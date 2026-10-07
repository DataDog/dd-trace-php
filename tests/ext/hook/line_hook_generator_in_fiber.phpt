--TEST--
A generator driven from inside a Fiber keeps its own range bookkeeping
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip requires PHP 8.1'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function gen() {
    $a = 1;                     // 4  begin
    yield $a;
    Fiber::suspend('mid');
    $b = 2;
    yield $b;                   // 8  end
}

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 4, $b, 8, $e);

$f = new Fiber(function () {
    $out = [];
    foreach (gen() as $v) { $out[] = $v; }
    return $out;
});
var_dump($f->start());
$f->resume();
var_dump($f->getReturn());
echo implode(',', $log), "\n";
$s = implode(',', $log);
var_dump(substr_count($s, 'B') === substr_count($s, 'E'));
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(3) "mid"
array(2) {
  [0]=>
  int(1)
  [1]=>
  int(2)
}
B4,E9
bool(true)
Done.
