--TEST--
Autoloading a JIT-compiled file preserves the enclosing hook's active trace
--DESCRIPTION--
The fixture intentionally defines no class, so each lookup executes it again.
After both the hook and fixture become hot, a new branch in the hook must use
the hook's exit metadata instead of the autoloaded file's zero-exit trace.
This exercises normal return from autoload.
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: JIT is only available on PHP 8+');
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
if (ini_get('opcache.jit') === false) die('skip: OPcache built without JIT');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--INI--
datadog.trace.sources_path="{PWD}"
opcache.enable=1
opcache.enable_cli=1
opcache.file_update_protection=0
opcache.jit_buffer_size=64M
opcache.jit=1255
opcache.jit_hot_func=1
opcache.jit_hot_loop=1
opcache.jit_hot_return=1
opcache.jit_hot_side_exit=1
--FILE--
<?php
var_dump(opcache_get_status(false)['jit']['on']);

function observed_autoload(int $value): int
{
    return $value;
}

$sum = 0;
DDTrace\install_hook('observed_autoload', null,
    static function (DDTrace\HookData $hook) use (&$sum) {
        $value = $hook->args[0];
        class_exists('DDTrace\\JitAutoload');
        // Take a new side exit after both JIT traces have warmed up.
        if ($value === 950) {
            $sum += 12345;
            return;
        }
        $sum += ($value & 1) ? $value : -$value;
    }
);

for ($value = 0; $value < 1000; ++$value) {
    observed_autoload($value);
}

var_dump($sum);
var_dump(in_array(realpath(__DIR__ . '/DDTrace/JitAutoload.php'), get_included_files(), true));
?>
--EXPECT--
bool(true)
int(13795)
bool(true)
