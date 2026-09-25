--TEST--
DDTrace\install_line_hook() ranges pair begin with end, and end still runs when control flow escapes
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

function plain() {
    $a = 1;         // 4  begin
    $b = 2;         // 5
    $c = 3;         // 6  end
    return $a + $b + $c;
}

function escapes_by_return($early) {
    $a = 1;         // 11 begin
    if ($early) {
        return 'early';
    }
    $b = 2;         // 15 end
    return 'late';
}

function escapes_by_throw() {
    $a = 1;         // 20 begin
    throw new RuntimeException('boom');
    $b = 2;         // 22 end
}

$log = [];
$mk = function ($tag) use (&$log) { return function () use (&$log, $tag) { $log[] = $tag; }; };

$a = DDTrace\install_line_hook(__FILE__, 4, $mk('plain:begin'), 6, $mk('plain:end'));
$b = DDTrace\install_line_hook(__FILE__, 11, $mk('ret:begin'), 15, $mk('ret:end'));
$c = DDTrace\install_line_hook(__FILE__, 20, $mk('throw:begin'), 22, $mk('throw:end'));
var_dump($a < 0 && $b < 0 && $c < 0);

plain();
echo implode(',', $log), "\n";

$log = [];
escapes_by_return(true);
echo "early: ", implode(',', $log), "\n";

$log = [];
escapes_by_return(false);
echo "late: ", implode(',', $log), "\n";

$log = [];
try {
    escapes_by_throw();
} catch (RuntimeException $e) {
}
echo "throw: ", implode(',', $log), "\n";

echo "Done.\n";
?>
--EXPECT--
bool(true)
plain:begin,plain:end
early: ret:begin,ret:end
late: ret:begin,ret:end
throw: throw:begin,throw:end
Done.
