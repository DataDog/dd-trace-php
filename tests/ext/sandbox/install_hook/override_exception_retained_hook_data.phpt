--TEST--
overrideException() on a HookData kept after its end hook releases each override exactly once
--SKIPIF--
<?php if (PHP_VERSION_ID < 70400) die('skip WeakReference requires PHP 7.4'); ?>
--INI--
zend.exception_ignore_args=1
--FILE--
<?php
function hooked() {
    throw new LogicException("original");
}

DDTrace\install_hook('hooked', function (DDTrace\HookData $hook) {
    $GLOBALS['hook'] = $hook;
    $hook->overrideException(new Exception("override"));
});

try {
    hooked();
} catch (Exception $e) {
    echo "caught: ", get_class($e), " ", $e->getMessage(), "\n";
}

// The thrown override is owned by $e now
$hook->overrideException(null);
$other = new RuntimeException("reuse");
echo "still: ", get_class($e), " ", $e->getMessage(), "\n";

// An override that is never thrown is released with the HookData
$never = new Exception("never thrown");
$weak = WeakReference::create($never);
$hook->overrideException($never);
unset($never, $hook);
echo "released: ", $weak->get() === null ? "yes" : "no", "\n";
?>
--EXPECT--
caught: Exception override
still: Exception override
released: yes
