--TEST--
A delayed OOM raised by a hook closure is tracked on the hooked span
--SKIPIF--
<?php if (getenv('USE_ZEND_ALLOC') === '0') die('skip Zend memory manager required'); ?>
<?php if (ini_get('memory_hard_limit_percent') === false) die('skip PHP without delayed OOM (8.2.35, 8.3.36, 8.4.27, 8.5.12+)'); ?>
--ENV--
DD_TRACE_MEMORY_LIMIT=1G
--INI--
memory_limit=32M
--FILE--
<?php
include __DIR__ . '/../dd_dumper.inc';
register_shutdown_function(function () {
    foreach (dd_clean_spans() as $span) {
        echo $span['name'], " error=", $span['error'] ?? 0, " ", $span['attributes']['error.type'] ?? '-', ": ", $span['attributes']['error.message'] ?? '-', "\n";
    }
});

function hooked() {
}

DDTrace\install_hook('hooked', function (DDTrace\HookData $hook) {
    $hook->span()->name = 'hooked';
}, function (DDTrace\HookData $hook) {
    $big = str_repeat('x', 64 << 20);
});

hooked();
?>
--EXPECTF--
Fatal error: Allowed memory size of 33554432 bytes exhausted %S(tried to allocate %d bytes) in %s on line %d
hooked error=1 E_ERROR: Allowed memory size of 33554432 bytes exhausted %S(tried to allocate %d bytes)
