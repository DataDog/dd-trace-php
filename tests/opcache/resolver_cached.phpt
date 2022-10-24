--TEST--
Verify functionality depending on class linking observation works with opcache loading
--INI--
opcache.enable=1
opcache.enable_cli=1
--FILE--
<?php

$cmdAndArgs = explode("\0", trim(file_get_contents("/proc/" . getmypid() . "/cmdline"), "\0"));
array_pop($cmdAndArgs);
array_push($cmdAndArgs, __DIR__ . "/resolver_cached.file.php");
$cmd = implode(" ", array_map("escapeshellarg", $cmdAndArgs));

// Execute twice, once primed
passthru($cmd);
passthru($cmd);

?>
--EXPECT--
NegativeClass::negative_method
negative_function
NegativeClass::negative_method
negative_function
