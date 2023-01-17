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
    #[DDTrace\Traced(name: "simplename", resource: "rsrc", type: "typeee", service: "test", tags: ["a" => "b", 1 => "ignored"])]
    static function simple($arg) {}

    #[DDTrace\Traced(args: ["foo"])]
    static function argRestricted($bar, $foo) {
        return 2;
    }

    #[DDTrace\Traced(args: true, return: true)]
    static function allArgs($bar, $foo) {
        return 2;
    }
}

#[DDTrace\Traced]
function bar() {
    Foo::simple(1);
}

#[DDTrace\Traced]
function recursion($i = 2) {
    if ($i) {
        recursion($i - 1);
    }
}

#[DDTrace\Traced(recurse: false)]
function noRecursion($i = 2) {
    if ($i) {
        noRecursion($i - 1);
    }
}

bar();
Foo::argRestricted("foo", "bar");
Foo::allArgs([[1, 2, []], 3, 4, 5, 6, true, null, "9!", false, 11, 12, 13], "bar", "additional");
recursion();
noRecursion();

dd_dump_spans();

?>
--EXPECT--
spans(\DDTrace\SpanData) (5) {
  bar (traced_attribute.php, bar, cli)
    _dd.p.dm => -1
    simplename (test, rsrc, typeee)
      a => b
  Foo.argRestricted (traced_attribute.php, Foo.argRestricted, cli)
    arg.foo => bar
    _dd.p.dm => -1
  Foo.allArgs (traced_attribute.php, Foo.allArgs, cli)
    arg.bar => [0 => [0 => '1', 1 => '2', 2 => [size 0][]], 1 => '3', 2 => '4', 3 => '5', 4 => '6', 5 => true, 6 => null, 7 => '9!', 8 => false, 9 => '11', ...]
    arg.foo => bar
    arg.2 => additional
    return_value => 2
    _dd.p.dm => -1
  recursion (traced_attribute.php, recursion, cli)
    _dd.p.dm => -1
    recursion (traced_attribute.php, recursion, cli)
      recursion (traced_attribute.php, recursion, cli)
  noRecursion (traced_attribute.php, noRecursion, cli)
    _dd.p.dm => -1
}
