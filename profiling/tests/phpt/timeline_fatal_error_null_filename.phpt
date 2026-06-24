--TEST--
[profiling] fatal error with a NULL file location must not crash the timeline error observer
--DESCRIPTION--
Regression test for a NULL pointer dereference in the timeline error observer.

When an uncaught exception whose `file` property is NULL is reported, the
engine calls the error notification with file=NULL (location "Unknown"). The
profiler's timeline error observer used to call CStr::from_ptr(NULL) (PHP 8.0,
where the observer receives a raw C string), which segfaulted in strlen().

Reproduces the upstream Zend/tests/bug50005.phpt and bug64821.3.phpt crashes
that only triggered with the profiler loaded and timeline enabled.
--SKIPIF--
<?php
if (!extension_loaded('datadog-profiling'))
    echo "skip: test requires Datadog Continuous Profiler\n";
// The crash is specific to PHP 8.0: the error observer receives a raw C
// string there (NULL-unsafe), while 8.1+ gets a NULL-safe zend_string. Also,
// Exception::$file became a typed `string` in 8.1, so `$this->file = null`
// throws a TypeError and can no longer produce a NULL-file fatal at all.
if (PHP_VERSION_ID < 80000 || PHP_VERSION_ID >= 80100)
    echo "skip: NULL-file fatal error observer crash is specific to PHP 8.0\n";
?>
--ENV--
DD_PROFILING_ENABLED=yes
DD_PROFILING_TIMELINE_ENABLED=yes
DD_PROFILING_ALLOCATION_ENABLED=no
DD_PROFILING_EXCEPTION_ENABLED=no
DD_PROFILING_LOG_LEVEL=off
--FILE--
<?php

class a extends exception {
    public function __construct() {
        $this->file = null;
    }
}

throw new a;

?>
--EXPECTF--
Fatal error: Uncaught a in :%d
Stack trace:
#0 {main}
  thrown in Unknown on line %d
