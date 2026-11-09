--TEST--
Line hooks resolve into trait methods shared by two classes, closures, and generators suspended across a yield
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

trait T {
    public function m($tag) {
        $v = $tag;
        return $v;
    }
}
class A { use T; }
class B { use T; }

function gen($n) {
    $acc = 0;
    for ($i = 0; $i < $n; $i++) {
        $acc += $i;
        yield $acc;
    }
    return $acc;
}

$closure = function ($x) {
    $y = $x * 2;
    return $y;
};

$log = [];
$mk = function ($tag) use (&$log) {
    return function (DDTrace\LineHookData $h) use (&$log, $tag) { $log[] = $tag . $h->line; };
};

// A trait method is flattened into every using class, and the classes share one opcodes array, so one arm serves both.
$t = DDTrace\install_line_hook(__FILE__, 5, $mk('T'));
(new A)->m('a');
(new B)->m('b');
echo "trait: ", implode(',', $log), "\n";
DDTrace\remove_hook($t);

// A closure lives in its declaring op_array's dynamic_func_defs on PHP 8 and in the function table on PHP 7.
$log = [];
$c = DDTrace\install_line_hook(__FILE__, 22, $mk('C'));
$closure(3);
$closure(4);
echo "closure: ", implode(',', $log), "\n";
DDTrace\remove_hook($c);

// A generator frame is suspended at the yield and resumed later; the range must pair up per iteration regardless.
$log = [];
$g = DDTrace\install_line_hook(__FILE__, 15, $mk('B'), 16, $mk('E'));
$sum = 0;
foreach (gen(3) as $v) {
    $sum += $v;
}
echo "generator: ", implode(',', $log), " sum=$sum\n";
DDTrace\remove_hook($g);

// Abandoned mid-iteration: the generator is destroyed while suspended inside the range, and the range must still close.
$log = [];
$g = DDTrace\install_line_hook(__FILE__, 15, $mk('B'), 16, function (DDTrace\LineHookData $h) use (&$log) {
    // PHP 8 frees the generator's frame before this observer runs and hands it a stack copy of the header, so the accessors must report nothing rather than read the C stack where the CV slots used to be.
    // PHP 7 frees the frame later, in free_obj, so there the CVs are genuinely still readable.
    $expected = PHP_VERSION_ID >= 80000 ? 0 : 3;
    $got = count($h->vars());
    $log[] = 'E' . $h->line . '/vars=' . ($got === $expected ? 'ok' : "BAD:$got");
});
foreach (gen(3) as $v) {
    break;
}
gc_collect_cycles();
echo "abandoned: ", implode(',', $log), "\n";
DDTrace\remove_hook($g);

echo "Done.\n";
?>
--EXPECT--
trait: T5,T5
closure: C22,C22
generator: B15,E15,B15,E15,B15,E18 sum=4
abandoned: B15,E16/vars=ok
Done.
