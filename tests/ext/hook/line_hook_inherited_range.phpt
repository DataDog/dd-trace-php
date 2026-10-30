--TEST--
A range on an inherited method closes once per call through every subclass
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

class Base {
    public function m($early) {
        $seen = static::class;      // 5  begin
        if ($early) {
            return 'early';
        }
        return 'late';              // 9  end
    }
    public function withStatics() {
        static $n = 0;
        $n++;                       // 13 begin
        return $n;                  // 14 end
    }
}
class Kid1 extends Base {}
class Kid2 extends Base {}

$log = [];
$b = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'B' . $h->line; };
$e = function (DDTrace\LineHookData $h) use (&$log) { $log[] = 'E' . $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 5, $b, 9, $e);

foreach (['Base', 'Kid1', 'Kid2'] as $cls) {
    foreach ([true, false] as $early) {
        $log = [];
        (new $cls())->m($early);
        printf("%s early=%d %s\n", $cls, (int)$early, implode(',', $log));
    }
}
DDTrace\remove_hook($id);

// Static variables force the engine to copy the zend_function per subclass.
$id2 = DDTrace\install_line_hook(__FILE__, 13, $b, 14, $e);
foreach (['Base', 'Kid1', 'Kid2'] as $cls) {
    $log = [];
    (new $cls())->withStatics();
    printf("%s statics %s\n", $cls, implode(',', $log));
}
DDTrace\remove_hook($id2);
echo "Done.\n";
?>
--EXPECT--
Base early=1 B5,E9
Base early=0 B5,E9
Kid1 early=1 B5,E9
Kid1 early=0 B5,E9
Kid2 early=1 B5,E9
Kid2 early=0 B5,E9
Base statics B13,E14
Kid1 statics B13,E14
Kid2 statics B13,E14
Done.
