--TEST--
Closure::bind and Closure::call rebindings keep firing the same armed line once per call
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

class Ctx {
    public $v = 'ctx';
}

$peek = function () {
    return $this->v;                    // 8
};

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 8, $p);

$bound = Closure::bind($peek, new Ctx(), Ctx::class);
var_dump($bound());
var_dump($peek->call(new Ctx()));
$b2 = $peek->bindTo(new Ctx(), Ctx::class);
var_dump($b2());
echo count($log), ': ', implode(',', $log), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(3) "ctx"
string(3) "ctx"
string(3) "ctx"
3: 8,8,8
Done.
