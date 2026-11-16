--TEST--
A hook aimed at a property hook body must not land on an unrelated file-scope line
--SKIPIF--
<?php if (PHP_VERSION_ID < 80400) die('skip requires PHP 8.4'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php
$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$id = DDTrace\install_line_hook(__FILE__, 9, $p);

class WithHooks {
    private string $raw = 'r';
    public string $shout {
        get { return strtoupper($this->raw); }      // 9 <- wanted
    }
}

$marker = 1;                                        // 13
$o = new WithHooks();
var_dump($o->shout);
echo ($log ? implode(',', $log) : '(never fired)'), "\n";
DDTrace\remove_hook($id);
echo "Done.\n";
?>
--EXPECT--
string(1) "R"
9
Done.
