--TEST--
End hooks can collect a backtrace after a sandboxed fatal error
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: PHP 8 observers'); ?>
--ENV--
DD_TRACE_LOG_LEVEL=off
--INI--
datadog.trace.generate_root_span=0
datadog.trace.auto_flush_enabled=0
--FILE--
<?php
function fail_in_hook() {
    trigger_error('sandbox fatal', E_USER_ERROR);
}

function application() {
    echo "application continues\n";
}

DDTrace\install_hook('fail_in_hook', null, static function () {
    debug_backtrace();
    echo "end hook\n";
});
DDTrace\install_hook('application', static function () {
    fail_in_hook();
}, static function () {
    echo "application end\n";
});

application();
echo "done\n";
?>
--EXPECT--
end hook
application continues
application end
done
