--TEST--
A range on an implicitly observed `yield from` leaf is refused rather than half-delivered
--DESCRIPTION--
A `yield from` chain gets implicit records for its leaves so their yields and resumptions reach the outer generator's hooks.
Those records carry no hook payload at all, so handing one out as a frame record had callers walk an indeterminate pointer.
Zeroing it is not enough either: a range with nowhere to record itself would deliver a begin whose end could never come, so the begin is withheld too.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: implicit yield from records are a PHP 8 interceptor path');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function leaf() {
    yield 1;
    $x = 2;                                 // 5
    yield $x;
}

function outer() {
    yield from leaf();
}

DDTrace\install_hook('outer', function () { echo "  outer begin\n"; },
                              function () { echo "  outer end\n"; });

$g = outer();
$g->current();                              // advances into the leaf's first yield

// Installed while the leaf is suspended and only implicitly observed.
$id = DDTrace\install_line_hook(__FILE__, 5, function () { echo "  line begin\n"; },
                                         6, function () { echo "  line end\n"; });
var_dump($id < 0);
$g->next();
$g->next();

DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
  outer begin
bool(true)
  outer end
Done.
