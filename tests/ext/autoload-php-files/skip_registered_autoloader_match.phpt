--TEST--
Skip previously registered autoloader on match
--INI--
datadog.trace.sources_path="{PWD}/.."
--FILE--
<?php

spl_autoload_register(function($class) {
    echo "Autoloading: $class\n";
});

var_dump(class_exists('DDTrace\SanityCheck'));
var_dump(class_exists('DDTrace\DoesNotExist'));

echo "Request start" . PHP_EOL;

?>
--EXPECT--
bool(true)
Autoloading: DDTrace\DoesNotExist
bool(false)
Request start
