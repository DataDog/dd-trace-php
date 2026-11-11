--TEST--
Line hooks reach retained nested closure copies without retaining their captures
--SKIPIF--
<?php if (PHP_VERSION_ID < 70400) die('skip WeakReference requires PHP 7.4'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$capture = new stdClass();
$weak = WeakReference::create($capture);
$file = __DIR__ . '/line_hook_retained_nested_closure.inc';
$outer = require $file;
$closure = $outer();
$copy = clone $closure;
$bound = $closure->bindTo(new stdClass());
unset($capture, $outer);

$id = DDTrace\install_line_hook($file, 4, function () { echo "begin\n"; }, 4,
    function () { echo "end\n"; });
var_dump($closure());
var_dump($copy());
var_dump($bound());

unset($closure, $copy, $bound);
gc_collect_cycles();
echo "capture released: ", var_export($weak->get() === null, true), "\n";
DDTrace\remove_hook($id);
?>
--EXPECT--
begin
end
string(6) "called"
begin
end
string(6) "called"
begin
end
string(6) "called"
capture released: true
