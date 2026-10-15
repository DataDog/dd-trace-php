--TEST--
#[DDTrace\Trace] attributes: a self-referencing object attribute becomes "*RECURSION*" at the cycle, and empty attributes are accepted
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip: new in attribute arguments requires PHP 8.1'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';
class SelfRef {
    public $me;
    public $v = 1;
    public function __construct() { $this->me = $this; }
}

#[DDTrace\Trace(name: "recursive", attributes: ["s" => new SelfRef])]
function recursive() {}

#[DDTrace\Trace(name: "empty", attributes: [])]
function emptyAttributes() {}

recursive();
emptyAttributes();

foreach (dd_clean_spans() as $span) {
    echo $span['name'], ": ", json_encode(isset($span['attributes']['s']) ? $span['attributes']['s'] : null), "\n";
}
?>
--EXPECT--
empty: null
recursive: {"me":"*RECURSION*","v":1}
