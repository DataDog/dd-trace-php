--TEST--
A line logged in the first request before module RINIT does not use a stale ext/date timezone cache
--DESCRIPTION--
On NTS PHP with error_log set to a file, a line logged during module startup
is timestamped in local time, which creates ext/date's timezone cache on the
startup heap. php_module_startup() discards that heap, so DATEG(tzcache)
dangles until date's first RINIT. A line logged in the first request before
the module RINITs (here, the warning that the POST data exceeds post_max_size)
used to look the timezone up in that freed table.

php-cgi runs module startup and one request in the same process. The
deprecation notice for allow_url_include is logged at the end of module
startup, after date's globals are initialized. The stale read is silent with
the Zend allocator, so the test only means something in the valgrind job
(run-tests -m with USE_TRACKED_ALLOC=1, where the startup allocations are
really freed): without the fix, valgrind reports invalid reads in
php_date_parse_tzfile() and the test is marked LEAK.
--SKIPIF--
<?php
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') die('skip: not on Windows');
if (PHP_ZTS) die('skip: NTS only, ZTS timestamps startup log lines without the timezone cache');
if (PHP_VERSION_ID < 70400) die('skip: allow_url_include is deprecated since PHP 7.4');
// run-tests -m sets this; without valgrind the stale read goes unnoticed
if (!getenv('ZEND_DONT_UNLOAD_MODULES')) die('skip: only meaningful under valgrind');
?>
--INI--
allow_url_include=1
display_errors=0
log_errors=1
error_log={TMP}/dd_date_tzcache_stale_first_request.log
error_reporting=-1
post_max_size=1K
--POST--
a=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
--FILE--
<?php
$log = file_get_contents(ini_get('error_log'));
echo 'Logged during module startup: ', var_export(strpos($log, "Directive 'allow_url_include' is deprecated") !== false, true), PHP_EOL;
echo 'Logged before module RINIT: ', var_export(strpos($log, 'exceeds the limit of 1024 bytes') !== false, true), PHP_EOL;
?>
--CLEAN--
<?php
@unlink(sys_get_temp_dir() . '/dd_date_tzcache_stale_first_request.log');
?>
--EXPECT--
Logged during module startup: true
Logged before module RINIT: true
