--TEST--
DDTrace\HookData can be subclassed without overrunning its allocation
--DESCRIPTION--
dd_hook_data_create() allocates a fixed sizeof(dd_hook_data) whose private C fields begin immediately after the
declared property slots, so a subclass's own properties are written past the allocation. The class is not final,
so nothing prevents it; no hook needs to be installed to reach the overrun.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// Enough declared properties that the overrun cannot hide in the struct's trailing C fields.
class Sub extends DDTrace\HookData {
    public $p01 = 1;  public $p02 = 2;  public $p03 = 3;  public $p04 = 4;
    public $p05 = 5;  public $p06 = 6;  public $p07 = 7;  public $p08 = 8;
    public $p09 = 9;  public $p10 = 10; public $p11 = 11; public $p12 = 12;
    public $extra = 'extra';
}

$s = new Sub();
var_dump($s->extra);
var_dump($s->p01 + $s->p12);
var_dump($s instanceof DDTrace\HookData);

// The base class still works across a real hook.
function target($x) {
    return $x * 2;
}

$id = DDTrace\install_hook('target',
    function (DDTrace\HookData $h) { var_dump($h->id > 0, $h->args); },
    function (DDTrace\HookData $h) { var_dump($h->returned); });
var_dump(target(21));
DDTrace\remove_hook($id);

echo "Done.\n";
?>
--EXPECT--
string(5) "extra"
int(13)
bool(true)
bool(true)
array(1) {
  [0]=>
  int(21)
}
int(42)
int(42)
Done.
