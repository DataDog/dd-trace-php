--TEST--
Warn on dd_trace usage only once
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=1
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die("skip: the warning is different on PHP 5.4"); ?>
--FILE--
<?php
dd_trace('dd_trace_noop', function () {});
dd_trace('dd_trace_noop', function () {});
echo "Done.\n";
?>
--EXPECT--
dd_trace DEPRECATION NOTICE: the function `dd_trace` (target: dd_trace_noop) is deprecated and will become a no-op in the next release, and eventually will be removed. Please follow https://github.com/DataDog/dd-trace-php/issues/924 for instructions to update your code; set DD_TRACE_WARN_LEGACY_DD_TRACE=0 to suppress this warning.
Done.
