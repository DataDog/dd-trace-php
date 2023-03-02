--TEST--
Warn on dd_trace usage only once
--ENV--
DD_TRACE_WARN_LEGACY_DD_TRACE=1
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
dd_trace('dd_trace_noop', function () {});
dd_trace('dd_trace_noop', function () {});
error_reporting(E_ALL);
echo "Done.\n";
?>
--EXPECT--
dd_trace DEPRECATION NOTICE: the function `dd_trace` (target: dd_trace_noop) is deprecated and has become a no-op since 0.48.0, and will eventually be removed. Please follow https://github.com/DataDog/dd-trace-php/issues/924 for instructions to update your code; set DD_TRACE_WARN_LEGACY_DD_TRACE=0 to suppress this warning.
Done.
