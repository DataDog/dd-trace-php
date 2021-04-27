--TEST--
Request init hook loads files without using multibyte flag
--INI--
zend.multibyte=1
ddtrace.request_init_hook={PWD}/contains_binary_character.php
--FILE--
<?php
echo "Request start" . PHP_EOL;

?>
--EXPECT--
SUCCESS
Request start
