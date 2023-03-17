--TEST--
Profiling should only be enabled after preloading has happened
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
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.preload={PWD}/preload_01_preload.php
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_LOG_LEVEL=debug
--FILE--
<?php
echo "Done.", PHP_EOL;
?>
--EXPECTREGEX--
.*
.* zend_post_startup_cb hasn't happened yet; not enabling profiler.
preloading
.* Memory allocation profiling enabled.
.* Started with an upload period of 67 seconds and approximate wall-time period of 10 milliseconds.
Done.
.* Stopping profiler.
.* Notified other threads of cancellation.
.* No profiles to upload.
