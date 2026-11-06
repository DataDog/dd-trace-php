--TEST--
Line hooks fire inside __call, __get, __set, __invoke and __toString
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

class Magic {
    private $bag = [];
    public function __call($name, $args) {
        return $name . '/' . count($args);      // 6
    }
    public function __get($name) {
        return 'get:' . $name;                  // 9
    }
    public function __set($name, $value) {
        $this->bag[$name] = $value;             // 12
    }
    public function __invoke($x) {
        return 'invoke:' . $x;                  // 15
    }
    public function __toString() {
        return 'str';                           // 18
    }
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$ids = [];
foreach ([6, 9, 12, 15, 18] as $ln) { $ids[] = DDTrace\install_line_hook(__FILE__, $ln, $p); }

$m = new Magic();
var_dump($m->nope(1, 2));
var_dump($m->prop);
$m->prop = 'v';
var_dump($m(7));
var_dump((string)$m);
echo implode(',', $log), "\n";
foreach ($ids as $id) { DDTrace\remove_hook($id); }
echo "Done.\n";
?>
--EXPECT--
string(6) "nope/2"
string(8) "get:prop"
string(8) "invoke:7"
string(3) "str"
6,9,12,15,18
Done.
