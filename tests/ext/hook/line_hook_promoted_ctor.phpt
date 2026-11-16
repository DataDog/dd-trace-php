--TEST--
Line hooks resolve inside a constructor with promoted readonly parameters
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip requires PHP 8.1'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

class Pt {
    public function __construct(
        public readonly int $x,         // 5
        public readonly int $y,         // 6
    ) {
        $this->extra();                 // 8
    }
    private function extra() {
        return 'e';                     // 11
    }
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$ids = [];
foreach ([8, 11] as $ln) { $ids[] = DDTrace\install_line_hook(__FILE__, $ln, $p); }

$pt = new Pt(1, 2);
var_dump($pt->x, $pt->y);
echo implode(',', $log), "\n";
foreach ($ids as $id) { DDTrace\remove_hook($id); }
echo "Done.\n";
?>
--EXPECT--
int(1)
int(2)
8,11
Done.
