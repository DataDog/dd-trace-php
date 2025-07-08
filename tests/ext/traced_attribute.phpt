--TEST--
Test tracing via attributes
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: No attributes pre-PHP 8'); ?>
--ENV--
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

include __DIR__ . '/sandbox/dd_dumper.inc';

class Foo {
    #[DDTrace\Trace(name: "simplename", resource: "rsrc", type: "typeee", service: "test", tags: ["a" => "b", "data" => "dog", 1 => "ignored"])]
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
--EXPECTF--
spans(\DDTrace\SpanData) (3) {
  bar (traced_attribute.php, bar, cli)
    _dd.code_origin.frames.0.file => %s
    _dd.code_origin.frames.0.line => 22
    _dd.code_origin.frames.0.method => bar
    _dd.code_origin.frames.1.file => %s
    _dd.code_origin.frames.1.line => 1
    _dd.code_origin.type => entry
    _dd.p.dm => -0
    _dd.p.tid => %s
    simplename (test, rsrc, typeee)
      _dd.base_service => traced_attribute.php
      _dd.code_origin.frames.0.file => %s
      _dd.code_origin.frames.0.line => 7
      _dd.code_origin.frames.0.method => simple
      _dd.code_origin.frames.0.type => Foo
      _dd.code_origin.frames.1.file => %s
      _dd.code_origin.frames.1.line => 22
      _dd.code_origin.frames.1.method => bar
      _dd.code_origin.frames.2.file => %s
      _dd.code_origin.frames.2.line => 1
      _dd.code_origin.type => exit
      a => b
      data => dog
  recursion (traced_attribute.php, recursion, cli)
    _dd.code_origin.frames.0.file => %s
    _dd.code_origin.frames.0.line => 27
    _dd.code_origin.frames.0.method => recursion
    _dd.code_origin.frames.1.file => %s
    _dd.code_origin.frames.1.line => 1
    _dd.code_origin.type => entry
    _dd.p.dm => -0
    _dd.p.tid => %s
    recursion (traced_attribute.php, recursion, cli)
      recursion (traced_attribute.php, recursion, cli)
  noRecursion (traced_attribute.php, noRecursion, cli)
    _dd.code_origin.frames.0.file => %s
    _dd.code_origin.frames.0.line => 34
    _dd.code_origin.frames.0.method => noRecursion
    _dd.code_origin.frames.1.file => %s
    _dd.code_origin.frames.1.line => 1
    _dd.code_origin.type => entry
    _dd.p.dm => -0
    _dd.p.tid => %s
}
