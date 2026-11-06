--TEST--
Line hooks resolve against the file-scope op_array, and a line inside a function does not slide onto it
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$log = [];
$probe = function (DDTrace\LineHookData $h) use (&$log) {
    $log[] = $h->line . ':' . var_export($h->var('x'), true);
};

// Every enclosing op_array brackets a nested one's lines, so the file scope is a candidate for line 13 as well -- but its own first line >= 13 is 16, which must lose to inner()'s exact 13.
$b = DDTrace\install_line_hook(__FILE__, 13, $probe);

function inner() {
    $x = 'fn';
    return $x;
}

$x = 'mid';
inner();
var_dump($log);
DDTrace\remove_hook($b);

// The file-scope op_array is a candidate in its own right; 25 is blank and 26 a comment, so it slides to 27.

$log = [];
$a = DDTrace\install_line_hook(__FILE__, 25, $probe);

// comment
$x = 'top';
var_dump($log);
DDTrace\remove_hook($a);

echo "Done.\n";
?>
--EXPECT--
array(1) {
  [0]=>
  string(7) "13:'fn'"
}
array(1) {
  [0]=>
  string(8) "27:'mid'"
}
Done.
