--TEST--
priority_sampling regression for GH-1828
--DESCRIPTION--
When DD_TRACE_SAMPLING_RULES was set explicitly to the empty array, the
ZAI/config component would try to increase the reference count on the global,
immutable `zend_empty_array` when printing out `phpinfo()`.
--ENV--
DD_TRACE_SAMPLING_RULES='[]'
--FILE--
<?php

$ddtrace = new ReflectionExtension('ddtrace');

// This tests for a crash regression, no need for specific output.
ob_start();
$ddtrace->info();
ob_clean();

echo "Done.\n";

?>
--EXPECT--
Done.
