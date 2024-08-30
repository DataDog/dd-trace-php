--TEST--
Execute the default spl_autoload implementation if spl_autoload_register() is called without args
--INI--
datadog.trace.sources_path="{PWD}/.."
--FILE--
<?php

spl_autoload_register();

var_dump(class_exists('splautoload'));

echo "Request start" . PHP_EOL;

?>
--EXPECT--
bool(true)
Request start
