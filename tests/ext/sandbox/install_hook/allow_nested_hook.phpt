--TEST--
allowNestedHook()
--FILE--
<?php
function foo() {
    var_dump('foo');
}

$count = 0;
$hook = DDTrace\install_hook("foo", function($hook) use (&$count) {
    var_dump("hook called: $count");
    $count++;
    if ($count == 1) {
        $hook->allowNestedHook();
        foo();
    } else if ($count == 2) {
        foo();
    }
});

echo "Before hook:\n";
foo();

DDTrace\remove_hook($hook);

$count = 0;
$hook = DDTrace\install_hook("foo", function ($hook) {}, function($hook) use (&$count) {
    var_dump("hook called: $count");
    $count++;
    if ($count == 1) {
        $hook->allowNestedHook();
        foo();
    } else if ($count == 2) {
        foo();
    }
});

echo "After hook:\n";
foo();

?>
--EXPECT--
Before hook:
string(14) "hook called: 0"
string(14) "hook called: 1"
string(3) "foo"
string(3) "foo"
string(3) "foo"
After hook:
string(3) "foo"
string(14) "hook called: 0"
string(3) "foo"
string(14) "hook called: 1"
string(3) "foo"
