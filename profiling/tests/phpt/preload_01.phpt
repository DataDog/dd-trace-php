--TEST--
[profiling] Profiling should only be enabled after preloading has happened
--DESCRIPTION--
This is a special case for when PHP-FPM running as non-root user and preloading
is enabled. In this case, PHP will do preloading in the php-fpm: master process
and not in one of it's childs. This will start profiling (including the threads)
in the master process already before the fork happens to create workers. In the
worker processes the global state indicates that profiling is running, but all
handles to the threads and channels are invalid leading to those channels
filling up and PHP-FPM blocking ultimately.
See: https://github.com/DataDog/dd-trace-php/issues/1919
Due to limitations of PHPT, this test does not test PHP-FPM or processes run
with different permissions, but it makes sure, that profiling is started after
preloading is done.
--ENV--
USE_ZEND_ALLOC=0
--SKIPIF--
<?php
if (PHP_VERSION_ID < 70400)
    echo "skip: need preloading and therefore PHP >= 7.4.0", PHP_EOL;
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires datadog-profiling", PHP_EOL;
if (!extension_loaded('Zend OPcache'))
    echo "skip: test requires opcache", PHP_EOL;
?>
--INI--
datadog.profiling.enabled=yes
datadog.profiling.log_level=debug
opcache.enable_cli=1
opcache.preload={PWD}/preload_01_preload.php
opcache.preload_user=root
--FILE--
<?php
/*
 * this `usleep()` makes sure profiling will start and take a sample. This is because
 * we need to make sure that the "Started with an upload period ..." message
 * comes after the "preloading" output from the preloading script.
 */
usleep(11000);
echo "Done.", PHP_EOL;
?>
--EXPECTREGEX--
.*zend_post_startup_cb hasn't happened yet; not enabling profiler.
preloading
.*Started with an upload period of [0-9]+ seconds and approximate wall-time period of [0-9]+ milliseconds.
.*Done.*
