--TEST--
#[DDTrace\Trace] tags: an empty $tags is not deprecated, and a self-referencing object tag becomes "*RECURSION*" at the cycle
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip: new in attribute arguments requires PHP 8.1'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_AUTO_FLUSH_ENABLED=0
DD_CODE_ORIGIN_FOR_SPANS_ENABLED=0
--INI--
error_reporting=E_ALL
--FILE--
<?php
include __DIR__ . '/sandbox/dd_dumper.inc';
class SelfRef {
    public $me;
    public $v = 1;
    public function __construct() { $this->me = $this; }
}

#[DDTrace\Trace(name: "recursive", tags: ["s" => new SelfRef])]
function recursive() {}

#[DDTrace\Trace(name: "empty", tags: [])]
function emptyTags() {}

echo "calls\n";
recursive();
emptyTags();

foreach (dd_clean_spans() as $span) {
    echo $span['name'], ": ", json_encode(isset($span['attributes']['s']) ? $span['attributes']['s'] : null), "\n";
}
?>
--EXPECTF--
Deprecated: DDTrace\Trace: $tags is deprecated, use $attributes instead in %s on line %d
calls
empty: null
recursive: {"me":"*RECURSION*","v":"1"}
