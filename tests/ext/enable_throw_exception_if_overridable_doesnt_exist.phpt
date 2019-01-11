--TEST--
Toggle checking if overridable method/function exists or not
--INI--
ddtrace.ignore_missing_overridables=0
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
unexpected parameter combination, expected (class, function, closure) or (function, closure)
