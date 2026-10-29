--TEST--
DDTrace\LineHookData can be subclassed without overrunning its allocation
--DESCRIPTION--
The class is not final, so the engine will happily allocate a subclass through its create_object handler.
That handler allocated a fixed sizeof(), with the private frame pointer sitting exactly where the subclass's first property slot lands -- so object_properties_init() wrote past the allocation and over the frame pointer.
No hook needs to be installed to reach it.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

// Enough declared properties that the overrun cannot hide in whatever slack the struct happens to have.
class Sub extends DDTrace\LineHookData {
    public $p01 = 1;  public $p02 = 2;  public $p03 = 3;  public $p04 = 4;
    public $p05 = 5;  public $p06 = 6;  public $p07 = 7;  public $p08 = 8;
    public $p09 = 9;  public $p10 = 10; public $p11 = 11; public $p12 = 12;
    public $extra = 'extra';
}

$s = new Sub();
var_dump($s->extra);
var_dump($s->p01 + $s->p12);
var_dump($s->vars());
var_dump($s->var('anything'));
var_dump($s instanceof DDTrace\LineHookData);

// The base class still carries its own properties across a real dispatch.
function target() {
    $local = 'v';                           // 20
    return $local;
}

$id = DDTrace\install_line_hook(__FILE__, 20, function (DDTrace\LineHookData $h) {
    var_dump($h->id < 0, basename($h->file), $h->line, $h->vars());
});
var_dump(target());
DDTrace\remove_hook($id);

echo "Done.\n";
?>
--EXPECTF--
string(5) "extra"
int(13)
array(0) {
}
NULL
bool(true)
bool(true)
string(%d) "line_hook_subclass.ph%s"
int(20)
array(0) {
}
string(1) "v"
Done.
