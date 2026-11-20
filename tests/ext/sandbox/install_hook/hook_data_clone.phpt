--TEST--
DDTrace\HookData cannot be cloned
--FILE--
<?php
function hooked() {
}

DDTrace\install_hook('hooked', function (DDTrace\HookData $hook) {
    try {
        $copy = clone $hook;
        $copy->overrideException(new Exception("override"));
        echo "cloned\n";
    } catch (Error $e) {
        echo get_class($e), ": ", $e->getMessage(), "\n";
    }
});

hooked();
echo "done\n";
?>
--EXPECT--
Error: Trying to clone an uncloneable object of class DDTrace\HookData
done
