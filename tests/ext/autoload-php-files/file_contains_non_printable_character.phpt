--TEST--
Request init hook loads files without using multibyte flag
--INI--
zend.multibyte=1
datadog.trace.sources_path="{PWD}/.."
--FILE--
<?php

class_exists('DDTrace\ContainsBinaryCharacter');

echo "Request start" . PHP_EOL;

?>
--EXPECT--
SUCCESS
Request start
