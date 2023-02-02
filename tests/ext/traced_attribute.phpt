--TEST--
Set DDTrace\start_span() properties
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: No attributes pre-PHP 8'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

include __DIR__ . '/sandbox/dd_dumper.inc';

class Foo {
    #[DDTrace\Trace(name: "simplename", resource: "rsrc", type: "typeee", service: "test", tags: ["a" => "b", 1 => "ignored"])]
    static function simple($arg) {}
/*
    #[DDTrace\Trace(saveArgs: ["foo"])]
    static function argRestricted($bar, $foo) {
        return 2;
    }

    #[DDTrace\Trace(saveArgs: true, saveReturn: true)]
    static function allArgs($bar, $foo) {
        return 2;
    }
*/
}

#[DDTrace\Trace]
function bar() {
    Foo::simple(1);
}

#[DDTrace\Trace]
function recursion($i = 2) {
    if ($i) {
        recursion($i - 1);
    }
}

#[DDTrace\Trace(recurse: false)]
function noRecursion($i = 2) {
    if ($i) {
        noRecursion($i - 1);
    }
}

bar();
/*
Foo::argRestricted("foo", "bar");
Foo::allArgs([[1, 2, []], 3, 4, 5, 6, true, null, "9!", false, 11, 12, 13], "bar", "additional");
*/
recursion();
noRecursion();

dd_dump_spans();

?>
--EXPECT--
spans(\DDTrace\SpanData) (3) {
  bar (traced_attribute.php, bar, cli)
    _dd.p.dm => -1
    simplename (test, rsrc, typeee)
      a => b
  recursion (traced_attribute.php, recursion, cli)
    _dd.p.dm => -1
    recursion (traced_attribute.php, recursion, cli)
      recursion (traced_attribute.php, recursion, cli)
  noRecursion (traced_attribute.php, noRecursion, cli)
    _dd.p.dm => -1
}
