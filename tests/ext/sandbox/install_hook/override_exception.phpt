--TEST--
overrideException()
--FILE--
<?php
function foo() {
    return 'foo';
}

$hook = DDTrace\install_hook("foo", function($hook) {}, function($hook) {
    $hook->overrideException(new Exception('my exception'));
});

echo "With hook:\n";
try {
    var_dump(foo());
} catch (Exception $e) {
    echo "threw ", $e->getMessage(), "\n";
}

DDTrace\remove_hook($hook);

echo "Without hook:\n";
var_dump(foo());


function foo2() {
    throw new Exception('existing exception');
}
$hook = DDTrace\install_hook("foo2", function($hook) {}, function($hook) {
    $hook->overrideException(new Exception('my exception'));
});

echo "With hook:\n";
try {
    var_dump(foo2());
} catch (Exception $e) {
    echo "threw ", $e->getMessage(), "\n";
}


DDTrace\remove_hook($hook);

echo "Without hook:\n";
try {
    var_dump(foo2());
} catch (Exception $e) {
    echo "threw ", $e->getMessage(), "\n";
}

?>
--EXPECT--
With hook:
threw my exception
Without hook:
string(3) "foo"
With hook:
threw my exception
Without hook:
threw existing exception
