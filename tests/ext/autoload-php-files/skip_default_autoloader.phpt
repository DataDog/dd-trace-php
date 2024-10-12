--TEST--
Do not execute the default spl_autoload implementation if no autoloader is specified
--INI--
datadog.trace.sources_path="{PWD}/.."
--FILE--
<?php

var_dump(class_exists('splautoload'));

echo "Request start" . PHP_EOL;

?>
--EXPECT--
bool(false)
Request start
