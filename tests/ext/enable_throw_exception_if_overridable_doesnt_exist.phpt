--TEST--
Toggle checking if overrided class doesn't exist
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
