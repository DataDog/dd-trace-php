--TEST--
Overriding function return value via install_hook()
--FILE--
<?php

function simple() {
    return 1;
}

$global = 2;
function &ref() {
    global $global;
    return $global;
}

$hook = DDTrace\install_hook("simple", null, function($hook) {
    $hook->overrideReturnValue(3);
});
print "Value is replaced: "; var_dump(simple());

$refVal = 4;
$hook = DDTrace\install_hook("ref", null, function($hook) use (&$refVal) {
    $hook->overrideReturnValue($refVal);
});
$refsimple = &ref();
$refVal = 5;
print "Return value replaces by reference: "; var_dump($refsimple);
DDTrace\remove_hook($hook);

$hook = DDTrace\install_hook("ref", null, function($hook) {
    $hook->overrideReturnValue(6);
});
print "Reference unaltered: "; var_dump($global);
print "Return value altered: "; var_dump(ref());
DDTrace\remove_hook($hook);

$hook = DDTrace\install_hook("ref", null, function($hook) {
    $hook->returned += 5;
});
print "Original return value is a reference: "; var_dump(ref());

?>
--EXPECT--
Value is replaced: int(3)
Return value replaces by reference: int(5)
Reference unaltered: int(2)
Return value altered: int(6)
Original return value is a reference: int(7)
