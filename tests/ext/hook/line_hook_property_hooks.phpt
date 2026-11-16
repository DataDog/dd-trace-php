--TEST--
Line hooks resolve inside property hook get/set bodies
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip requires PHP 8.4'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

class WithHooks {
    private string $raw = 'r';

    public string $shout {
        get {
            return strtoupper($this->raw);      // 8
        }
        set {
            $this->raw = strtolower($value);    // 11
        }
    }

    public string $short { get => $this->raw . '!'; }    // 15
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$ids = [];
foreach ([8, 11, 15] as $ln) { $ids[] = DDTrace\install_line_hook(__FILE__, $ln, $p); }

$o = new WithHooks();
var_dump($o->shout);
$o->shout = 'ABC';
var_dump($o->shout);
var_dump($o->short);
echo implode(',', $log), "\n";
foreach ($ids as $id) { DDTrace\remove_hook($id); }
echo "Done.\n";
?>
--EXPECT--
string(1) "R"
string(3) "ABC"
string(4) "abc!"
8,11,8,15
Done.
