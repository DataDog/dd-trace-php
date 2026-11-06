--TEST--
A trait range that can only close at frame exit does so for every using class
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

trait T {
    public function m($early) {
        $a = 1;                 // 5  begin
        if ($early) {
            return 'early';     // the only way out; there is no opline past line 8
        }
        return 'late';          // 9  end
    }
}

class A { use T; }
class B { use T; }
class C { use T; }

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 5, $b, 9, $e);

foreach (['A', 'B', 'C'] as $cls) {
    foreach ([true, false] as $early) {
        $log = [];
        $o = new $cls();
        $o->m($early);
        printf("%s early=%d %s\n", $cls, (int)$early, implode(',', $log));
    }
}
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
A early=1 B5,E9
A early=0 B5,E9
B early=1 B5,E9
B early=0 B5,E9
C early=1 B5,E9
C early=0 B5,E9
Done.
