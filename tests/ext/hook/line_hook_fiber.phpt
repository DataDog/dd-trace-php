--TEST--
A line hook range suspended by a Fiber still gets exactly one end per begin
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip requires PHP 8.1'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function inFiber($t) {
    $a = $t;                        // 4  begin
    Fiber::suspend('s:' . $a);
    $b = $a . '!';                  // 6
    return $b;                      // 7  end
}

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 4, $b, 7, $e);

$f = new Fiber(function () { return inFiber('x'); });
var_dump($f->start());
echo 'mid: ', implode(',', $log), "\n";
$f->resume();
var_dump($f->getReturn());
echo 'end: ', implode(',', $log), "\n";
$s = implode(',', $log);
var_dump(substr_count($s, 'B') === substr_count($s, 'E'));
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(3) "s:x"
mid: B4
string(2) "x!"
end: B4,E7
bool(true)
Done.
