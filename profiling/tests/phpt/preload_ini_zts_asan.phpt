--TEST--
[profiling] ASAN regression: ZTS preloading must not duplicate INI values from themselves
--DESCRIPTION--
During preloading in ZTS builds, EG(ini_directives) is still the global INI
table. The ZTS INI synchronization step must be skipped in that state. If it is
not skipped, it releases zend_ini_entry.value and then duplicates the same
pointer, which ASAN reports as a use-after-free.
--ENV--
USE_ZEND_ALLOC=0
DD_PROFILING_ENABLED=0
DD_PROFILING_LOG_LEVEL=error
--SKIPIF--
<?php
if (!getenv('SKIP_ASAN'))
    echo "skip: test requires ASAN run-tests mode\n";
if (!PHP_ZTS)
    echo "skip: test requires PHP ZTS\n";
if (PHP_VERSION_ID < 70400)
    echo "skip: need preloading and therefore PHP >= 7.4.0\n";
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires datadog-profiling\n";
if (!extension_loaded('Zend OPcache'))
    echo "skip: test requires opcache\n";
?>
--INI--
opcache.enable_cli=1
opcache.preload={PWD}/preload_ini_zts_asan_preload.php
opcache.preload_user=root
--FILE--
<?php
echo "main ini ok: ", ini_get('datadog.profiling.log_level') === 'error' ? 'yes' : 'no', PHP_EOL;
?>
--EXPECTREGEX--
^(?:\[TRACE datadog_php_profiling\] MINIT\([0-9]+, [0-9]+\)\n)*preload ini ok: yes\nmain ini ok: yes\n?$
