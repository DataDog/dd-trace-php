--TEST--
Arrow functions share their line with the enclosing scope, so both sites arm
--SKIPIF--
<?php if (PHP_VERSION_ID < 70400) die('skip requires PHP 7.4'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };

function build() {
    return fn($x) => $x * 3;            // 7
}

$id = DDTrace\install_line_hook(__FILE__, 7, $p);

$f = build();                           // arms fire: build()'s line 7, then the arrow body
var_dump($f(2));
var_dump($f(3));
echo count($log), ': ', implode(',', $log), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
int(6)
int(9)
3: 7,7,7
Done.
