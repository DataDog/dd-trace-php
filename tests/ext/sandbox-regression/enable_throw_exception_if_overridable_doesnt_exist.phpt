--TEST--
[Sandbox regression] Toggle checking if overrided class doesn't exist
--SKIPIF--
<?php if (PHP_VERSION_ID < 50500) die('skip PHP 5.4 not supported'); ?>
--INI--
ddtrace.strict_mode=1
--FILE--
<?php
try {
    dd_trace("ThisClassDoesntExists", "m", function(){
        return  $this->m() . "METHOD HOOK" . PHP_EOL;
    });
} catch (InvalidArgumentException $ex) {
    echo $ex->getMessage() . PHP_EOL;
}

?>
--EXPECTF--
class not found
