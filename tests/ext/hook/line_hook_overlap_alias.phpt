--TEST--
Overlap validation recognises the same file written two different ways
--DESCRIPTION--
ddtrace_uhook_line_conflict() compared the caller's string for exact equality, so the same file named once as __FILE__ and once as its basename looked like two files and a partial overlap slipped through -- producing exactly the interleaved Ab,Bb,Ae,Be the check exists to prevent.
Both spellings resolve through the same suffix match that install_line_hook() itself uses, so validation has to as well.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function body() {
    $a = 1;                                 // 4
    $b = 2;                                 // 5
    $c = 3;                                 // 6
    $d = 4;                                 // 7
    return $a + $b + $c + $d;               // 8
}

function other() {
    $p = 1;                                 // 12
    $q = 2;                                 // 13
    $r = 3;                                 // 14
    $s = 4;                                 // 15
    return $p + $q + $r + $s;               // 16
}

$log = [];
$mk = function ($tag) use (&$log) {
    return function (DDTrace\LineHookData $h) use (&$log, $tag) { $log[] = $tag . $h->line; };
};

// Full path first, basename second.
$a = DDTrace\install_line_hook(__FILE__, 4, $mk('Ab'), 6, $mk('Ae'));
var_dump($a < 0);
try {
    DDTrace\install_line_hook(basename(__FILE__), 5, $mk('Bb'), 7, $mk('Be'));
    echo "accepted (wrong)\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

// Basename first, full path second.
$c = DDTrace\install_line_hook(basename(__FILE__), 12, $mk('Cb'), 14, $mk('Ce'));
var_dump($c < 0);
try {
    DDTrace\install_line_hook(__FILE__, 13, $mk('Db'), 15, $mk('De'));
    echo "accepted (wrong)\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

// A genuinely disjoint range in the same file is still accepted under either spelling.
$e = DDTrace\install_line_hook(basename(__FILE__), 7, $mk('Eb'), 8, $mk('Ee'));
var_dump($e < 0);

var_dump(body());
var_dump(other());
echo implode(',', $log), "\n";

DDTrace\remove_hook($a);
DDTrace\remove_hook($c);
DDTrace\remove_hook($e);
echo "Done.\n";
?>
--EXPECT--
bool(true)
Line hook range partially overlaps an existing range in the same file
bool(true)
Line hook range partially overlaps an existing range in the same file
bool(true)
int(10)
int(10)
Ab4,Ae7,Eb7,Ee8,Cb12,Ce15
Done.
