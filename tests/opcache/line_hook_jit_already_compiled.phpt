--TEST--
A line hook in a function the JIT already compiled is reported rather than silently not delivered
--DESCRIPTION--
Blacklisting stops the JIT taking new traces, but nothing can discard a trace that already exists -- so a hook put into an already-hot function keeps returning a valid id while the compiled code runs straight past the instrumented instruction.
That is the one JIT case that cannot be fixed, so it is at least made visible.
--SKIPIF--
<?php
if (!extension_loaded('Zend OPcache')) die('skip: Zend OPcache is required');
// From 8.4 opcache exports zend_jit_blacklist_function() but nothing that says what is already compiled, so the condition still applies there -- it just cannot be detected, and therefore cannot be reported.
if (PHP_VERSION_ID >= 80400) die('skip: already-compiled detection needs the pre-8.4 trace_info layout');
// PHP 8.0 only ships the JIT on x86_64, so on other architectures there is no JIT to detect at all -- and the version alone cannot say so.
// The jit INI entries are registered only by a JIT-capable build, which makes this a property of the build rather than of the version. opcache_get_status() is no good here: SKIPIF does not get the test's --INI--, so opcache is not enabled yet and it would return false on every version.
if (ini_get('opcache.jit_buffer_size') === false) die('skip: no JIT in this opcache build');
if (getenv('DD_TRACE_CLI_ENABLED') === '0') die('skip: tracer is disabled');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=warn,span=off,startup=off
DD_APPSEC_ENABLED=0
--INI--
datadog.trace.log_file=file://stdout
opcache.enable=1
opcache.enable_cli=1
opcache.file_update_protection=0
opcache.jit_buffer_size=64M
opcache.jit=tracing
opcache.jit_hot_func=1
opcache.jit_hot_loop=1
--FILE--
<?php

function already_hot($n) {
    $s = 0;
    for ($i = 0; $i < $n; $i++) {
        $s += $i;                           // 6
    }
    return $s;
}

// Drive the same function hard enough that the tracing JIT has compiled it before the hook goes anywhere near it.
for ($k = 0; $k < 200; ++$k) {
    already_hot(40);
}

$hits = 0;
$id = DDTrace\install_line_hook(__FILE__, 6, function () use (&$hits) { ++$hits; });
var_dump($id < 0);
for ($k = 0; $k < 10; ++$k) {
    already_hot(40);
}
DDTrace\remove_hook($id);

echo "Done.\n";
?>
--EXPECTF--
[ddtrace] [warning] [%d] Line hook installed at %sline_hook_jit_already_compiled.php:6 in a function the JIT had already compiled: the compiled code does not consult the instrumented instruction, so the hook may not fire there in %s on line %d;%s
bool(true)
Done.
