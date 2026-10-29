--TEST--
LineHookData::var()/vars() see dynamically created variables and do not read an unattached symbol table
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// A frame only has EX(symbol_table) once something forces one into existence; on a frame without one that slot holds whatever the VM stack was last used for, so a lookup there has to be gated on the frame's flag rather than on the pointer being non-NULL.
function plain($arg) {
    $local = 1;
    return $arg;
}

function dynamic($arg) {
    $src = ['dyn' => 'D'];
    extract($src);
    $local = 1;
    return $arg;
}

$seen = [];
$probe = function (DDTrace\LineHookData $h) use (&$seen) {
    $seen[$h->line] = [
        'arg' => $h->var('arg'),
        'local' => $h->var('local'),
        'dyn' => $h->var('dyn'),
        'nope' => $h->var('nope'),
        'vars' => (function ($v) { ksort($v); return array_keys($v); })($h->vars()),
    ];
};

$a = DDTrace\install_line_hook(__FILE__, 6, $probe);
$b = DDTrace\install_line_hook(__FILE__, 13, $probe);

plain('A');
dynamic('B');

var_dump($seen[6]);
var_dump($seen[13]);

DDTrace\remove_hook($a);
DDTrace\remove_hook($b);
echo "Done.\n";
?>
--EXPECT--
array(5) {
  ["arg"]=>
  string(1) "A"
  ["local"]=>
  int(1)
  ["dyn"]=>
  NULL
  ["nope"]=>
  NULL
  ["vars"]=>
  array(2) {
    [0]=>
    string(3) "arg"
    [1]=>
    string(5) "local"
  }
}
array(5) {
  ["arg"]=>
  string(1) "B"
  ["local"]=>
  int(1)
  ["dyn"]=>
  string(1) "D"
  ["nope"]=>
  NULL
  ["vars"]=>
  array(4) {
    [0]=>
    string(3) "arg"
    [1]=>
    string(3) "dyn"
    [2]=>
    string(5) "local"
    [3]=>
    string(3) "src"
  }
}
Done.
