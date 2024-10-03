--TEST--
Test delayed resolution of tracing attributes
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: No attributes pre-PHP 8'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

include __DIR__ . '/sandbox/dd_dumper.inc';

$eval = <<<'EVAL'
eval("class Bar {}");

class Foo extends Bar {
    #[DDTrace\Trace(name: "simpleclass")]
    static function simple($arg) { return $arg; }
}
EVAL;

$oldId = DDTrace\install_hook("somefunction", function() {});
eval($eval);
$newId = DDTrace\install_hook("somefunction", function() {});

Foo::simple(1);

var_dump($newId - $oldId - 1); // we check how many hooks were installed in between, to ensure that it was installed only once

$eval = <<<'EVAL'
if (time()) {
    #[DDTrace\Trace(name: "simplefunc")]
    function simple($arg) { return $arg; }
}
EVAL;

$oldId = DDTrace\install_hook("somefunction", function() {});
eval($eval);
$newId = DDTrace\install_hook("somefunction", function() {});

simple(1);

var_dump($newId - $oldId - 1); // we check how many hooks were installed in between, to ensure that it was installed only once

dd_dump_spans();

?>
--EXPECTF--
int(1)
int(1)
spans(\DDTrace\SpanData) (2) {
  simpleclass (traced_attribute_delayed.php, simpleclass, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
  simplefunc (traced_attribute_delayed.php, simplefunc, cli)
    _dd.p.dm => -0
    _dd.p.tid => %s
}
