--TEST--
Check method can be overwritten and we're able to call original method returning an array
--SKIPIF--
<?php if (PHP_VERSION_ID < 70000) die("skip: requires dd_trace support"); ?>
--FILE--
<?php
class Test {
    public function m($arg){
        return [$arg];
    }
}

dd_trace("Test", "m", function($arg){
    return array_merge($this->m("METHOD"), [$arg]);
});

echo implode(PHP_EOL, (new Test())->m("HOOK"));

?>
--EXPECT--
METHOD
HOOK
