--TEST--
A delayed OOM raised in an end hook while an exception is pending terminates the request
--SKIPIF--
<?php if (getenv('USE_ZEND_ALLOC') === '0') die('skip Zend memory manager required'); ?>
<?php if (ini_get('memory_hard_limit_percent') === false) die('skip PHP without delayed OOM (8.2.35, 8.3.36, 8.4.27, 8.5.12+)'); ?>
--INI--
memory_limit=32M
--FILE--
<?php
register_shutdown_function(function () {
    echo "shutdown: ", error_get_last()['message'] ?? 'no error', "\n";
});

function hooked() {
    throw new Exception("user");
}

DDTrace\install_hook('hooked', null, function () {
    $big = str_repeat('x', 64 << 20);
    echo "not reached\n";
});

try {
    hooked();
} catch (Exception $e) {
    echo "caught ", $e->getMessage(), "\n";
}
echo "not reached\n";
?>
--EXPECTF--
Fatal error: Allowed memory size of 33554432 bytes exhausted %S(tried to allocate %d bytes) in %s on line %d
shutdown: Allowed memory size of 33554432 bytes exhausted %S(tried to allocate %d bytes)
